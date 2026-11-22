<?php

namespace App\Filament\Owner\Pages;

use App\Filament\Support\Ui;
use App\Finance\TrustAccounting;
use App\Maintenance\MaintenanceService;
use App\Models\Owner;
use App\Models\OwnershipShare;
use App\Models\WorkOrder;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Owner interface (spec §7.3, MVP items): monthly statement with the manager's fee shown (REQ-0305, REQ-0306) and
 * one-tap approval of maintenance above the manager's cap (REQ-0307). KPIs are on the KPI page (REQ-0304).
 */
class OwnerHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = -10;   // the role home opens first (the panel root redirects here)

    protected static ?string $navigationLabel = 'كشف الحساب والموافقات';

    protected static ?string $title = 'كشف الحساب والموافقات';

    protected static ?string $slug = 'statement';

    protected string $view = 'filament.owner.home';

    #[Url]
    public ?string $month = null;

    private function owner(): ?Owner
    {
        return Owner::where('user_id', auth()->id())->first();
    }

    protected function getViewData(): array
    {
        $owner = $this->owner();
        $month = CarbonImmutable::parse(($this->month ?? now()->format('Y-m')) . '-01');
        $statement = null;
        $error = null;
        if ($owner) {
            try {
                $statement = app(TrustAccounting::class)->statement($owner, $month);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $propertyIds = $owner ? OwnershipShare::where('owner_id', $owner->id)->pluck('property_id') : collect();
        return [
            'header' => app(\App\Dashboards\HomeOverview::class)->header(auth()->user(), CarbonImmutable::now()),
            'monthLabel' => \IntlDateFormatter::formatObject($month, 'MMMM y', 'ar-u-ca-gregory-nu-latn'),
            'months' => collect(range(0, 23))->mapWithKeys(fn ($i) => [($m = CarbonImmutable::now()->startOfMonth()->subMonths($i))->format('Y-m') => \IntlDateFormatter::formatObject($m, 'MMMM y', 'ar-u-ca-gregory-nu-latn')])->all(),
            'month' => $month->format('Y-m'), 'statement' => $statement, 'error' => $error,
            'pending' => WorkOrder::whereIn('property_id', $propertyIds)->where('status', 'awaiting_owner_approval')->get(),
        ];
    }

    public function decide(int $workOrderId, bool $approve): void
    {
        $owner = $this->owner();
        $wo = WorkOrder::findOrFail($workOrderId);
        abort_unless($owner && OwnershipShare::where('owner_id', $owner->id)->where('property_id', $wo->property_id)->exists(), 403);
        Ui::attempt(fn () => app(MaintenanceService::class)->ownerDecision($wo, auth()->user(), $approve), $approve ? 'تمت الموافقة' : 'رُفض الطلب');
    }
}
