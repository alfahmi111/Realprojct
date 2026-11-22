<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Portals\TenantPortal;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithFileUploads;

/**
 * Tenant interface (spec §7.1 MVP): my unit and its assets, my lease and notice deadline, my invoices and payment,
 * maintenance reports with photos and rating, announcements, meter-transfer guidance.
 */
class TenantHome extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'وحدتي';

    protected static ?string $title = 'وحدتي';

    protected static ?string $slug = 'my-unit';

    protected string $view = 'filament.tenant.home';

    public ?int $assetId = null;

    public string $description = '';

    public bool $emergency = false;

    public array $photos = [];

    protected function getViewData(): array
    {
        $p = app(TenantPortal::class);
        $u = auth()->user();
        try {
            return ['unit' => $p->myUnit($u), 'lease' => $p->myLease($u), 'invoices' => $p->myInvoices($u), 'reports' => $p->myReports($u), 'announcements' => $p->announcements($u), 'guidance' => $p->meterTransferGuidance()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['unit' => null, 'lease' => null, 'invoices' => [], 'reports' => [], 'announcements' => [], 'guidance' => $p->meterTransferGuidance()];
        }
    }

    public function report(): void
    {
        $this->validate(['description' => 'required|string|max:2000', 'photos.*' => 'image|max:8192']);
        $paths = array_map(fn ($f) => $f->store('subscribers/' . auth()->user()->subscriber_id . '/reports'), $this->photos);
        Ui::attempt(fn () => app(TenantPortal::class)->openReport(auth()->user(), $this->assetId ? Asset::find($this->assetId) : null, $this->description, $paths, $this->emergency), 'تم إرسال البلاغ');
        $this->reset(['assetId', 'description', 'emergency', 'photos']);
    }

    public function rate(int $workOrderId, int $rating): void
    {
        Ui::attempt(fn () => app(TenantPortal::class)->rate(auth()->user(), WorkOrder::findOrFail($workOrderId), $rating), 'شكراً لتقييمك');
    }

    public function pay(int $invoiceId): void
    {
        $link = Ui::attempt(fn () => app(TenantPortal::class)->payLink(auth()->user(), Invoice::findOrFail($invoiceId)), 'جارٍ تحويلك للدفع');
        if ($link) {
            $this->redirect($link['url']);
        }
    }
}
