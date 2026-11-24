<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\Ui;
use App\Finance\TrustAccounting;
use App\Models\Owner;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Owner monthly statement (REQ-0305, REQ-0431): collected − fee (with %) − maintenance per work order − margin = net. */
class OwnerStatementPage extends Page
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.statements');
    }
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'كشف حساب المالك';

    protected static ?string $title = 'كشف حساب المالك الشهري';

    protected static ?string $slug = 'owner-statement';

    public ?array $data = [];

    public ?array $statement = null;

    public function mount(): void
    {
        $first = \App\Filament\Support\PropertyScope::apply(Owner::query(), 'owner')->whereNull('archived_at')->orderBy('name_ar')->value('id');
        $this->form->fill(['month' => now()->format('Y-m'), 'owner_id' => $first]);
        if ($first) {
            $this->show(quiet: true);   // open on the first owner, this month
        }
    }

    public function getHeading(): string
    {
        return '';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('owner_id')->label('المالك')->options(fn () => \App\Filament\Support\PropertyScope::apply(Owner::query(), 'owner')->whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id'))->required()->live()->afterStateUpdated(fn () => $this->show(quiet: true)),
            TextInput::make('month')->label('الشهر')->type('month')->regex('/^\d{4}-\d{2}$/')->required()->live(onBlur: true)->afterStateUpdated(fn () => $this->show(quiet: true)),
        ])->columns(2);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('show')->footer([\Filament\Schemas\Components\Actions::make([Action::make('show')->label('عرض الكشف')->submit('show')])]),
        ]);
    }

    protected string $view = 'filament.manager.owner-statement';

    public function shiftMonth(int $dir): void
    {
        $m = CarbonImmutable::parse(($this->data['month'] ?? now()->format('Y-m')) . '-01')->addMonths($dir)->format('Y-m');
        $this->data['month'] = $m;
        $this->show(quiet: true);
    }

    public function show(bool $quiet = false): void
    {
        $d = $this->data ?? [];
        if (empty($d['owner_id']) || ! preg_match('/^\d{4}-\d{2}$/', (string) ($d['month'] ?? ''))) {
            $this->statement = null;
            return;
        }
        $s = Ui::attempt(function () use ($d) {
            $trust = app(TrustAccounting::class);
            $owner = \App\Filament\Support\PropertyScope::apply(Owner::query(), 'owner')->findOrFail($d['owner_id']);
            // M22: beside the month, what can be transferred today and what already waits for a checker
            $position = $trust->position($owner);
            return $trust->statement($owner, CarbonImmutable::parse($d['month'] . '-01'))
                + ['available_now' => $position['available'], 'position' => $position, 'pending_disbursements' => $trust->pendingDisbursements($owner)];
        }, $quiet ? '' : 'جاهز');
        $this->statement = $s ? $this->present($s) + ['owner' => Owner::find($d['owner_id'])?->name_ar] : null;
    }

    /**
     * Ask to transfer the owner's money out of trust (M22). The amount defaults to what is available now; the request
     * goes to the approval queue and the balance is checked again, under the owner's lock, when it is approved.
     */
    public function disburseAction(): Action
    {
        return Action::make('disburse')->label('طلب تحويل للمالك')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => $this->statement !== null && (bool) auth()->user()?->hasPermission('finance.disburse'))
            ->modalHeading('طلب تحويل من أمانات المالك')
            ->modalDescription(fn () => 'المتاح للصرف الآن: ' . ($this->statement['available_now'] ?? '—') . '. يُرسل الطلب لمراجِع يعتمده، ويُرفض عند الاعتماد إن لم يعد الرصيد يكفي.')
            ->schema([TextInput::make('amount')->label('المبلغ')->numeric()->minValue(0.01)->required()
                ->default(fn () => \App\Support\Money\Money::of(max(0, (int) ($this->statement['raw']['available_now'] ?? 0)))->toDecimalString())])
            ->action(function (array $data) {
                $owner = \App\Filament\Support\PropertyScope::apply(Owner::query(), 'owner')->findOrFail($this->data['owner_id']);
                Ui::attempt(fn () => app(TrustAccounting::class)->requestDisbursement(auth()->user(), $owner, \App\Support\Money\Money::parse((string) $data['amount']), \App\Support\Dates\Today::date()), 'أُرسل طلب التحويل للاعتماد');
                $this->show(quiet: true);
            });
    }

    private function present(array $s): array
    {
        $m = fn ($v) => \App\Support\Dates\NumberFormatter::money($v);   // the user's digits and currency sign
        $minor = fn ($v) => $v instanceof \App\Support\Money\Money ? $v->minor() : (int) $v;
        return [
            'month' => $s['month'], 'collected' => $m($s['collected_rent']), 'fee_pct' => intdiv($s['management_fee']['percentage_bp'], 100) . '.' . str_pad((string) ($s['management_fee']['percentage_bp'] % 100), 2, '0', STR_PAD_LEFT),
            'fee' => $m($s['management_fee']['amount']), 'fee_deducted' => $s['management_fee']['deducted'], 'fee_basis' => $s['management_fee']['basis'] === 'due' ? 'الإيجار المستحق' : 'المحصَّل', 'fee_vat' => $m($s['management_fee']['vat']),
            'maintenance' => array_map(fn ($x) => ['date' => $x['date'], 'description' => $x['description'], 'work_order' => $x['work_order'], 'amount' => $m($x['amount']), 'minor' => $minor($x['amount'])], $s['maintenance']),
            'maintenance_total' => $m($s['maintenance_total']), 'margin_total' => $m($s['vendor_margin_total']), 'net' => $m($s['net_to_owner']),
            'fee_text' => \App\Finance\TrustAccounting::feeText($s['management_fee']),
            'owner_direct' => $m($s['collected_by_owner']), 'recovered' => $m($s['maintenance_recovered']), 'recovered_vat' => $m($s['maintenance_recovered_vat']), 'has_recovered_vat' => $s['maintenance_recovered_vat']->isPositive(),
            // M22: running trust position of this owner
            'opening_available' => $m($s['opening_available']), 'disbursed' => $m($s['disbursed']), 'available' => $m($s['available']),
            'available_now' => $m($s['available_now']), 'pending_disbursements' => $m($s['pending_disbursements']), 'has_pending' => $s['pending_disbursements']->isPositive(),
            'disbursements' => array_map(fn ($x) => ['date' => $x['date'], 'amount' => $m($x['amount'])], $s['disbursements']),
            // cash and what the office is owed, apart; and the ledger-vs-statements check
            'trust_cash' => $m($s['position']['trust_cash']), 'owed_to_office' => $m($s['position']['owed_to_office']),
            'fees_owed' => $m($s['position']['fees_owed']), 'margins_owed' => $m($s['position']['margins_owed']),
            'owner_owes' => $m($s['position']['owner_owes']), 'has_owner_owes' => $s['position']['owner_owes']->isPositive(),
            'difference' => $m($s['position']['difference']), 'has_difference' => ! $s['position']['difference']->isZero(),
            'owner_paid' => array_map(fn ($x) => ['date' => $x['date'], 'work_order' => $x['work_order'], 'amount' => $m($x['amount'])], $s['maintenance_owner_paid']),
            'owner_paid_total' => $m($s['maintenance_owner_paid_total']),
            // integers for the waterfall bars
            'raw' => ['collected' => $minor($s['collected_rent']), 'fee' => $s['management_fee']['deducted'] ? $minor($s['management_fee']['amount']) : 0, 'vat' => $s['management_fee']['deducted'] ? $minor($s['management_fee']['vat']) : 0,
                'maintenance' => $minor($s['maintenance_total']), 'margin' => $minor($s['vendor_margin_total']), 'net' => $minor($s['net_to_owner']),
                'owner_direct' => $minor($s['collected_by_owner']), 'recovered' => $minor($s['maintenance_recovered']),
                'available_now' => $minor($s['available_now']), 'opening' => $minor($s['opening_available'])],
        ];
    }
}
