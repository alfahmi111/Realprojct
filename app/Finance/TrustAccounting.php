<?php

namespace App\Finance;

use App\Approvals\DualApproval;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ManagementAgreement;
use App\Models\Owner;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Trust accounting (spec §9.3) and the owner's monthly statement (spec §7.3):
 *   collected rent − management fee (its agreed percentage shown) − maintenance (each work order listed)
 *   − vendor margin (separate line, REQ-0306) = net transferred to the owner (REQ-0305, REQ-0431).
 * Separate ledger per owner (REQ-0428); owners' money in trust_bank, never in manager revenue (REQ-0429);
 * manager revenue = fees only (REQ-0430); advance rent recognised monthly (REQ-0432).
 */
class TrustAccounting
{
    public function __construct(private Ledger $ledger, private VatEngine $vat, private Settings $settings, private DualApproval $approvals)
    {
    }

    /** Recognise deferred rent for one month (REQ-0432). Idempotent per invoice and month. */
    public function recognizeMonth(CarbonImmutable $month): int
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $count = 0;
        $invoices = Invoice::where('kind', 'rent')->where('status', 'issued')
            ->where('period_start', '<=', $end->toDateString())->where('period_end', '>=', $start->toDateString())->get();
        foreach ($invoices as $inv) {
            $exists = JournalEntry::where('kind', 'revenue.recognition')->where('source_type', 'Invoice')->where('source_id', (string) $inv->id)
                ->where('entry_date', $end->toDateString())->exists();
            if ($exists) {
                continue;
            }
            $months = $this->monthKeys(CarbonImmutable::parse($inv->period_start), CarbonImmutable::parse($inv->period_end));
            // a full month weighs the same as any other full month; a partial month by its share of that month's days
            // (review M18: 15 Jan–14 Apr was recognised 25% in each of Jan, Feb, Mar and Apr)
            $pStart = CarbonImmutable::parse($inv->period_start);
            $pEnd = CarbonImmutable::parse($inv->period_end);
            $weights = array_map(function ($ym) use ($pStart, $pEnd) {
                $m = CarbonImmutable::parse($ym . '-01');
                $covered = (int) $m->max($pStart)->diffInDays($m->endOfMonth()->startOfDay()->min($pEnd)) + 1;
                return intdiv($covered * 10000, $m->daysInMonth);
            }, $months);
            $parts = $inv->subtotal_minor->allocate($weights);
            $idx = array_search($start->format('Y-m'), $months, true);
            if ($idx === false || $parts[$idx]->isZero()) {
                continue;
            }
            $this->ledger->post($end->toDateString(), 'revenue.recognition', 'إثبات إيراد شهر ' . $start->format('Y-m') . ' للفاتورة ' . $inv->number, [
                ["deferred_rent:{$inv->seller_owner_id}", $parts[$idx], null],
                ["rent_income:{$inv->seller_owner_id}", null, $parts[$idx]],
            ], $inv, $inv->seller_owner_id, $inv->property_id);
            $count++;
        }
        return $count;
    }

    /**
     * Expense paid from the owners' trust account on the owner's behalf (maintenance, etc.). Paying out of trust is a
     * sensitive entry (REQ-0069 "صرف من حساب أمانات الملاك"). A vendor margin added by the manager is its own line (REQ-0306).
     */
    public function requestExpense(User $maker, Owner $owner, int $propertyId, Money $vendorCost, string $descriptionAr, ?string $workOrderRef, CarbonImmutable $on, ?Money $margin = null): ApprovalRequest
    {
        $payload = ['owner_id' => $owner->id, 'property_id' => $propertyId, 'cost_minor' => $vendorCost->minor(), 'margin_minor' => $margin?->minor() ?? 0, 'description' => $descriptionAr, 'work_order' => $workOrderRef];
        return $this->approvals->submit($maker, 'trust.disburse', $payload, function () use ($owner, $propertyId, $vendorCost, $descriptionAr, $workOrderRef, $on, $margin) {
            DB::transaction(function () use ($owner, $propertyId, $vendorCost, $descriptionAr, $workOrderRef, $on, $margin) {
                $this->ledger->post($on->toDateString(), 'expense.maintenance', $descriptionAr . ($workOrderRef ? " ($workOrderRef)" : ''), [
                    ["expense_on_behalf:{$owner->id}", $vendorCost, null], ['trust_bank', null, $vendorCost],
                ], null, $owner->id, $propertyId, $workOrderRef);
                if ($margin && $margin->isPositive()) {
                    $this->ledger->post($on->toDateString(), 'expense.vendor_margin', 'هامش المدير على فاتورة المورّد' . ($workOrderRef ? " ($workOrderRef)" : ''), [
                        ["fee_expense:{$owner->id}", $margin, null], ['manager_revenue', null, $margin],
                    ], null, $owner->id, $propertyId);
                }
            });
        }, 'owner', (string) $owner->id);
    }

    /** The owner's monthly statement (REQ-0305, REQ-0306, REQ-0431). */
    public function statement(Owner $owner, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $agreement = ManagementAgreement::where('owner_id', $owner->id)->whereNull('archived_at')
            ->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->orderByDesc('starts_on')->first()
            ?? throw new FinanceException('No management agreement covers this owner for the month.');

        // Collected rent = live allocations of payments received in the month on the owner's rent invoices.
        $allocs = PaymentAllocation::query()
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded')->whereBetween('received_on', [$from, $to]))
            ->whereIn('invoice_id', Invoice::where('seller_owner_id', $owner->id)->where('kind', 'rent')->select('id'))
            ->with('payment')->get();
        $collectedGross = Money::sum($allocs->map(fn ($a) => $a->amount_minor)->all());
        // Net of VAT, in proportion to each invoice's tax share (integers only).
        $collectedNet = Money::zero();
        $netByProperty = [];
        foreach ($allocs as $a) {
            $inv = Invoice::find($a->invoice_id);
            $part = $a->amount_minor->multiplyRatio($inv->subtotal_minor->minor(), max(1, $inv->total_minor->minor()));
            $collectedNet = $collectedNet->plus($part);
            $netByProperty[(int) $inv->property_id] = ($netByProperty[(int) $inv->property_id] ?? Money::zero())->plus($part);
        }

        // Fee terms are office settings (owner's decision 2026-11-23): basis of the percentage, period of the fixed
        // amount, and whether the fee is deducted from the owner's transfer or invoiced to the owner separately.
        $basis = $this->settings->get('finance.fee.percentage_basis') ?? 'collected';
        $period = $this->settings->get('finance.fee.fixed_period') ?? 'monthly';
        $collection = $this->settings->get('finance.fee.collection') ?? 'deduct_from_remittance';
        $dueByProperty = [];
        if ($basis === 'due') {
            foreach (Invoice::where('seller_owner_id', $owner->id)->where('kind', 'rent')->where('status', 'issued')->whereBetween('issue_date', [$from, $to])->get() as $i) {
                $dueByProperty[(int) $i->property_id] = ($dueByProperty[(int) $i->property_id] ?? Money::zero())->plus($i->subtotal_minor);
            }
        }
        $byProperty = $basis === 'due' ? $dueByProperty : $netByProperty;
        $base = Money::sum(array_values($byProperty));
        // An owner may hold several agreements in force (different properties, different terms): each property's rent
        // carries the fee of the agreement that covers it; a fixed amount is due once per agreement (review M18 F12).
        $covering = ManagementAgreement::where('owner_id', $owner->id)->whereNull('archived_at')
            ->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->orderBy('starts_on')->with('properties')->get();
        $agreementOf = [];
        foreach ($covering as $ag) {
            foreach ($ag->properties as $p) {
                $agreementOf[(int) $p->id] = $ag;   // the latest agreement wins for a property
            }
        }
        $feePct = Money::zero();
        $parts = [];
        foreach ($byProperty as $pid => $amount) {
            $ag = $agreementOf[$pid] ?? $agreement;
            if ($ag->fee_type !== 'fixed') {
                $feePct = $feePct->plus($amount->percentage($ag->fee_percentage_bp));
            }
        }
        $feeFixed = Money::zero();
        foreach ($covering as $ag) {
            if ($ag->fee_type !== 'percentage' && $this->fixedFeeDue($ag, $month, $period)) {
                $feeFixed = $feeFixed->plus($ag->fee_fixed_minor);
            }
            $parts[] = ['type' => $ag->fee_type, 'percentage_bp' => $ag->fee_percentage_bp, 'fixed_part' => $ag->fee_type === 'percentage' ? Money::zero() : $ag->fee_fixed_minor, 'basis' => $basis];
        }
        $fee = $feePct->plus($feeFixed);
        $managerRegistered = (bool) $this->settings->get('finance.vat_registered');
        $feeTreatment = $this->vat->treatment('management_fee', null, $managerRegistered, 'long', $month->endOfMonth());
        $feeVat = $this->vat->tax($fee, $feeTreatment['rate_bp']);

        $maintenance = JournalEntry::where('owner_id', $owner->id)->where('kind', 'expense.maintenance')->whereBetween('entry_date', [$from, $to])->with('lines')->orderBy('entry_date')->get()
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'description' => $e->memo_ar, 'work_order' => $e->external_ref, 'amount' => $e->lines->firstWhere('account', "expense_on_behalf:{$owner->id}")->debit_minor]);
        $margins = JournalEntry::where('owner_id', $owner->id)->where('kind', 'expense.vendor_margin')->whereBetween('entry_date', [$from, $to])->with('lines')->get()
            ->map(fn ($e) => ['description' => $e->memo_ar, 'amount' => $e->lines->firstWhere('account', "fee_expense:{$owner->id}")->debit_minor]);
        $maintTotal = Money::sum($maintenance->pluck('amount')->all());
        $marginTotal = Money::sum($margins->pluck('amount')->all());

        // Only what the office actually holds is transferable (review M18): rent paid straight into the owner's account is
        // shown on its own line and is not "net to transfer"; the fee on it is then owed by the owner (a negative net).
        $ownerDirect = Money::sum($allocs->filter(fn ($a) => $a->payment?->paid_to === 'owner')->map(fn ($a) => $a->amount_minor)->all());
        $heldByManager = $collectedGross->minus($ownerDirect);
        // Maintenance the tenant was charged for (misuse) and paid back to the office: credited to the owner, net of VAT,
        // because the full repair cost was deducted above (review M18).
        $recharges = PaymentAllocation::query()
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded')->where('paid_to', 'manager')->whereBetween('received_on', [$from, $to]))
            ->whereIn('invoice_id', Invoice::where('seller_owner_id', $owner->id)->where('kind', 'maintenance_recharge')->select('id'))->get();
        $recovered = Money::zero();
        foreach ($recharges as $a) {
            $inv = Invoice::find($a->invoice_id);
            $recovered = $recovered->plus($a->amount_minor->multiplyRatio($inv->subtotal_minor->minor(), max(1, $inv->total_minor->minor())));
        }
        $deducted = $collection === 'deduct_from_remittance';
        $net = $heldByManager->plus($recovered)->minus($deducted ? $fee->plus($feeVat) : Money::zero())->minus($maintTotal)->minus($marginTotal);
        return [
            'owner_id' => $owner->id, 'month' => $month->format('Y-m'),
            'collected_rent' => $collectedGross, 'collected_rent_net_of_vat' => $collectedNet,
            'collected_by_owner' => $ownerDirect, 'held_by_manager' => $heldByManager, 'maintenance_recovered' => $recovered,
            'management_fee' => ['type' => $agreement->fee_type, 'percentage_bp' => $agreement->fee_percentage_bp, 'percentage_part' => $feePct, 'fixed_part' => $feeFixed, 'amount' => $fee, 'vat' => $feeVat, 'tax_code' => $feeTreatment['code'],
                'basis' => $basis, 'base' => $base, 'fixed_period' => $period, 'collection' => $collection, 'deducted' => $deducted, 'agreements' => count($parts) > 1 ? $parts : []],
            'maintenance' => $maintenance->all(), 'maintenance_total' => $maintTotal,
            'vendor_margin' => $margins->all(), 'vendor_margin_total' => $marginTotal,
            'net_to_owner' => $net,
        ];
    }

    /**
     * The fee as words for statements (review M18: fixed and mixed agreements read «0.00% من المحصَّل»):
     * «7.5% من المحصَّل»، «مبلغ ثابت 500.00»، «5% من المحصَّل ومبلغ ثابت 300.00»، or «لا أتعاب».
     */
    public static function feeText(array $fee): string
    {
        if (! empty($fee['agreements'])) {   // several agreements: each one's terms, joined
            return implode(' + ', array_unique(array_map(fn ($p) => self::feeText($p), $fee['agreements'])));
        }
        $bp = (int) ($fee['percentage_bp'] ?? 0);
        $pct = rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '% من ' . (($fee['basis'] ?? 'collected') === 'due' ? 'الإيجار المستحق' : 'المحصَّل');
        $fixed = $fee['fixed_part'] ?? null;
        $fixedText = $fixed instanceof Money && $fixed->isPositive() ? 'مبلغ ثابت ' . \App\Support\Dates\NumberFormatter::money($fixed) : null;
        return match ($fee['type'] ?? 'percentage') {
            'fixed' => $fixedText ?? 'مبلغ ثابت لا يستحق هذا الشهر',
            'mixed' => $pct . ($fixedText ? ' و' . $fixedText : ''),
            default => $bp > 0 ? $pct : 'لا أتعاب',
        };
    }

    /** Book the month's management fee: manager revenue = fees only (REQ-0430). */
    public function postFee(Owner $owner, array $statement): void
    {
        $fee = $statement['management_fee'];
        if ($fee['amount']->isZero()) {
            return;
        }
        $date = CarbonImmutable::parse($statement['month'] . '-01')->endOfMonth()->toDateString();
        // Deducted from the transfer → charged against the owner's trust balance; invoiced separately → owed by the owner.
        $debit = ($fee['deducted'] ?? true) ? "fee_expense:{$owner->id}" : "fee_receivable:{$owner->id}";
        $this->ledger->post($date, 'management_fee', 'أتعاب إدارة ' . $statement['month'], [
            [$debit, $fee['amount']->plus($fee['vat']), null],
            ['manager_revenue', null, $fee['amount']],
            ['vat_payable:manager', null, $fee['vat']],
        ], null, $owner->id);
    }

    /** Transfer the net to the owner from trust — sensitive entry (REQ-0069). */
    public function requestDisbursement(User $maker, Owner $owner, Money $amount, CarbonImmutable $on): ApprovalRequest
    {
        if (! $amount->isPositive()) {
            throw new FinanceException('Nothing to transfer.');
        }
        return $this->approvals->submit($maker, 'trust.disburse', ['owner_id' => $owner->id, 'amount_minor' => $amount->minor()], fn () => $this->ledger->post($on->toDateString(), 'owner.disbursement', 'تحويل صافي المالك', [
            ["owner_payable:{$owner->id}", $amount, null], ['trust_bank', null, $amount],
        ], null, $owner->id), 'owner', (string) $owner->id);
    }

    /** Manager revenue in a period — only fee kinds ever credit it (REQ-0430). */
    public function managerRevenue(string $from, string $to): Money
    {
        return $this->ledger->movements('manager_revenue', $from, $to)['credit'];
    }

    /** The fixed fee is charged once per period, counted from the agreement's start month (monthly = every month). */
    private function fixedFeeDue(ManagementAgreement $agreement, CarbonImmutable $month, string $period): bool
    {
        $every = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$period] ?? 1;
        $start = CarbonImmutable::parse($agreement->starts_on)->startOfMonth();
        $elapsed = ($month->year - $start->year) * 12 + ($month->month - $start->month);
        return $elapsed >= 0 && $elapsed % $every === 0;
    }

    private function monthKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $keys = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonthNoOverflow()) {
            $keys[] = $m->format('Y-m');
        }
        return $keys;
    }
}
