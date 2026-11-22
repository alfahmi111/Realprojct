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
            $parts = $inv->subtotal_minor->split(count($months));
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
        foreach ($allocs as $a) {
            $inv = Invoice::find($a->invoice_id);
            $collectedNet = $collectedNet->plus($a->amount_minor->multiplyRatio($inv->subtotal_minor->minor(), max(1, $inv->total_minor->minor())));
        }

        $feePct = $agreement->fee_type === 'fixed' ? Money::zero() : $collectedNet->percentage($agreement->fee_percentage_bp);
        $feeFixed = $agreement->fee_type === 'percentage' ? Money::zero() : $agreement->fee_fixed_minor;   // per statement month — QUESTIONS.md Q13
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

        $net = $collectedGross->minus($fee)->minus($feeVat)->minus($maintTotal)->minus($marginTotal);
        return [
            'owner_id' => $owner->id, 'month' => $month->format('Y-m'),
            'collected_rent' => $collectedGross, 'collected_rent_net_of_vat' => $collectedNet,
            'management_fee' => ['type' => $agreement->fee_type, 'percentage_bp' => $agreement->fee_percentage_bp, 'percentage_part' => $feePct, 'fixed_part' => $feeFixed, 'amount' => $fee, 'vat' => $feeVat, 'tax_code' => $feeTreatment['code']],
            'maintenance' => $maintenance->all(), 'maintenance_total' => $maintTotal,
            'vendor_margin' => $margins->all(), 'vendor_margin_total' => $marginTotal,
            'net_to_owner' => $net,
        ];
    }

    /** Book the month's management fee: manager revenue = fees only (REQ-0430). */
    public function postFee(Owner $owner, array $statement): void
    {
        $fee = $statement['management_fee'];
        if ($fee['amount']->isZero()) {
            return;
        }
        $date = CarbonImmutable::parse($statement['month'] . '-01')->endOfMonth()->toDateString();
        $this->ledger->post($date, 'management_fee', 'أتعاب إدارة ' . $statement['month'], [
            ["fee_expense:{$owner->id}", $fee['amount']->plus($fee['vat']), null],
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

    private function monthKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $keys = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonthNoOverflow()) {
            $keys[] = $m->format('Y-m');
        }
        return $keys;
    }
}
