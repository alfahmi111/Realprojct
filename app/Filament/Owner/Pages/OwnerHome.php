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
                $error = \App\Support\Messages::ar($e->getMessage());
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

    /**
     * The owner sets his own approval rule for maintenance spending (owner request 2026-11-24): every spend, above an
     * amount, or never. Changes the current management agreement; the change is audited (Auditable).
     */
    protected function getHeaderActions(): array
    {
        $current = fn () => ($o = $this->owner()) ? $o->agreements()->whereNull('archived_at')->where('starts_on', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()))->orderByDesc('starts_on')->first() : null;
        $R = \App\Filament\Manager\Resources\Owners\RelationManagers\AgreementsRelationManager::class;
        return [
            \Filament\Actions\Action::make('approvalRule')->label(fn () => 'موافقتي على الصرف: ' . (($a = $current()) ? $R::ruleText($a->spend_cap_minor) : '—'))
                ->icon(Heroicon::OutlinedShieldCheck)->color('gray')->visible(fn () => (bool) $current())
                ->fillForm(function () use ($current) {
                    $m = $current()?->spend_cap_minor?->minor();
                    return ['approval_rule' => $m === null ? 'none' : ($m === 0 ? 'all' : 'up_to'), 'spend_cap_minor' => $m ?: null];
                })
                ->schema([
                    \Filament\Forms\Components\Radio::make('approval_rule')->label('متى يحتاج صرف الصيانة موافقتك؟')->live()->required()
                        ->options(['all' => 'كل صرف يحتاج موافقتي', 'up_to' => 'يصرف المكتب حتى مبلغ أحدده، وما فوقه بموافقتي', 'none' => 'لا أشترط موافقتي']),
                    Ui::money('spend_cap_minor', 'المبلغ')->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('approval_rule') === 'up_to')
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('approval_rule') === 'up_to'),
                ])
                ->action(function (array $data) use ($current, $R) {
                    $a = $current();
                    $cap = $R::cap($data);
                    // an agreement is never edited: the old one is archived and a successor with the new rule starts today
                    // (PROJECT_LOCK 2.أ; review M18 — the portal used to rewrite the active agreement in place)
                    \Illuminate\Support\Facades\DB::transaction(function () use ($a, $cap) {
                        $a->archive();
                        app(\App\Properties\BuildingWizard::class)->createAgreement($a->owner, $a->properties()->get()->all(), [
                            'is_self' => $a->is_self, 'fee_type' => $a->fee_type, 'fee_percentage_bp' => $a->fee_percentage_bp, 'fee_fixed_minor' => $a->fee_fixed_minor,
                            'spend_cap_minor' => $cap === null ? null : \App\Support\Money\Money::of($cap), 'collector' => $a->collector,
                            'starts_on' => \App\Support\Dates\Today::string(), 'ends_on' => $a->ends_on?->toDateString(),
                        ]);
                    });
                    \Filament\Notifications\Notification::make()->title('حُفظ: ' . $R::ruleText($cap))->success()->send();
                }),
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
