<?php

namespace App\Filament\Technician\Pages;

use App\Filament\Support\Ui;
use App\Maintenance\FoundingInventory;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use App\Models\InventoryCount;
use App\Models\InventoryLine;
use App\Models\Space;
use App\Models\WorkOrder;
use App\Portals\TechnicianApp;
use App\Support\Money\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithFileUploads;

/**
 * Technician interface (spec §7.4): assigned work orders only, before/after photos, parts and time, QR scan,
 * founding-inventory tasks. Offline: the page loads offline-queue.js; operations queue in IndexedDB and replay to
 * /api/tech/sync (REQ-0495).
 */
class TechnicianHome extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = -10;   // the role home opens first (the panel root redirects here)

    protected static ?string $navigationLabel = 'مهامي';

    protected static ?string $title = 'مهامي';

    protected static ?string $slug = 'tasks';

    protected string $view = 'filament.technician.home';

    public array $before = [];

    public array $after = [];

    public array $cost = ['kind' => 'part', 'description' => '', 'quantity' => 1, 'unit_cost' => ''];

    public string $scan = '';

    public ?array $scanned = null;

    public ?int $countSpace = null;

    public array $line = ['qr' => '', 'present' => true, 'condition' => 5, 'note' => ''];

    public $linePhoto = null;

    protected function getViewData(): array
    {
        $tech = auth()->user();
        $openCount = InventoryCount::where('performed_by', $tech->id)->where('status', 'open')->first();
        return [
            'header' => app(\App\Dashboards\HomeOverview::class)->header($tech, CarbonImmutable::now()),
            'orders' => app(TechnicianApp::class)->assigned($tech),
            'count' => $openCount,
            'expected' => $openCount ? app(FoundingInventory::class)->expected($openCount) : [],
            'recorded' => $openCount ? InventoryLine::where('inventory_count_id', $openCount->id)->pluck('asset_id')->all() : [],
            'units' => Space::where('kind', 'unit')->whereNull('archived_at')->orderBy('code')->pluck('code', 'id'),
        ];
    }

    private function mine(int $id): WorkOrder
    {
        $wo = WorkOrder::findOrFail($id);
        abort_unless($wo->assignee_user_id === auth()->id(), 403);
        return $wo;
    }

    private function store(array $files): array
    {
        return array_map(fn ($f) => $f->store('subscribers/' . auth()->user()->subscriber_id . '/work-orders'), $files);
    }

    public function start(int $id): void
    {
        Ui::attempt(fn () => app(MaintenanceService::class)->start($this->mine($id), $this->store($this->before)), 'بدأ التنفيذ');
        $this->before = [];
    }

    public function addCost(int $id): void
    {
        $this->validate(['cost.description' => 'required', 'cost.quantity' => 'required|integer|min:1', 'cost.unit_cost' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/']]);
        Ui::attempt(fn () => app(MaintenanceService::class)->addCost($this->mine($id), $this->cost['kind'], $this->cost['description'], (int) $this->cost['quantity'], Money::parse($this->cost['unit_cost'])), 'سُجّلت التكلفة');
        $this->cost = ['kind' => 'part', 'description' => '', 'quantity' => 1, 'unit_cost' => ''];
    }

    public function complete(int $id): void
    {
        Ui::attempt(fn () => app(MaintenanceService::class)->complete($this->mine($id), $this->store($this->after)), 'أُنجز العمل');
        $this->after = [];
    }

    public function doScan(): void
    {
        $r = Ui::attempt(fn () => app(TechnicianApp::class)->scan(auth()->user(), trim($this->scan)), 'تم المسح');
        // Livewire state must be plain values: money is kept as a decimal string.
        $this->scanned = $r ? ['asset' => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $r['asset']), 'cumulative' => $r['history']['cumulative_cost']->toDecimalString()] : null;
    }

    public function startCount(): void
    {
        Ui::attempt(fn () => app(FoundingInventory::class)->start(Space::findOrFail($this->countSpace), auth()->user(), CarbonImmutable::today()), 'بدأ جرد التأسيس');
    }

    public function recordLine(int $countId): void
    {
        $this->validate(['linePhoto' => 'required|image|max:8192', 'line.qr' => 'required']);
        $count = InventoryCount::where('performed_by', auth()->id())->findOrFail($countId);
        $photo = $this->linePhoto->store('subscribers/' . auth()->user()->subscriber_id . '/inventory');
        Ui::attempt(fn () => app(FoundingInventory::class)->record($count, $this->line['qr'], (bool) $this->line['present'], $this->line['present'] ? (int) $this->line['condition'] : null, $photo, $this->line['note'] ?: null), 'سُجّل الأصل');
        $this->line = ['qr' => '', 'present' => true, 'condition' => 5, 'note' => ''];
        $this->linePhoto = null;
    }

    public function submitCount(int $countId): void
    {
        $count = InventoryCount::where('performed_by', auth()->id())->findOrFail($countId);
        Ui::attempt(fn () => app(FoundingInventory::class)->submit($count, 'signed:' . auth()->id() . ':' . now()->toIso8601String()), 'أُرسل الجرد');
    }
}
