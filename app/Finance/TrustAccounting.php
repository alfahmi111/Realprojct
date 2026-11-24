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
        SoleOwner::assert($propertyId, 'تكلفة الصيانة');   // M22: never charged to the first owner of a shared property
        $payload = ['owner_id' => $owner->id, 'property_id' => $propertyId, 'cost_minor' => $vendorCost->minor(), 'margin_minor' => $margin?->minor() ?? 0, 'description' => $descriptionAr, 'work_order' => $workOrderRef];
        // the balance is checked when the request is approved and posted, not here: a work order can close and its
        // payment wait in the approval queue until the owner's trust balance covers it
        return $this->approvals->submit($maker, 'trust.disburse', $payload,
            fn () => $this->postExpense($owner->id, $propertyId, $vendorCost, $margin, $descriptionAr, $workOrderRef, $on->toDateString()), 'owner', (string) $owner->id);
    }

    /**
     * Pay a cost out of trust for the owner (REQ-0069, REQ-0429). M22: under the owner's lock and in one transaction
     * with the posting, the owner's available trust balance must cover the cost and any manager margin.
     */
    public function postExpense(int $ownerId, int $propertyId, Money $cost, ?Money $margin, string $descriptionAr, ?string $workOrderRef, string $date): void
    {
        DB::transaction(function () use ($ownerId, $propertyId, $cost, $margin, $descriptionAr, $workOrderRef, $date) {
            SoleOwner::assert($propertyId, 'تكلفة الصيانة');
            $this->lockOwner($ownerId);
            $owner = Owner::findOrFail($ownerId);
            $need = $margin && $margin->isPositive() ? $cost->plus($margin) : $cost;
            $this->assertAvailable($owner, $need, 'تكلفة الصيانة', CarbonImmutable::parse($date));
            $this->ledger->post($date, 'expense.maintenance', $descriptionAr . ($workOrderRef ? " ($workOrderRef)" : ''), [
                ["expense_on_behalf:{$ownerId}", $cost, null], ['trust_bank', null, $cost],
            ], null, $ownerId, $propertyId, $workOrderRef);
            if ($margin && $margin->isPositive()) {
                $this->ledger->post($date, 'expense.vendor_margin', 'هامش المدير على فاتورة المورّد' . ($workOrderRef ? " ($workOrderRef)" : ''), [
                    ["fee_expense:{$ownerId}", $margin, null], ['manager_revenue', null, $margin],
                ], null, $ownerId, $propertyId);
            }
            // Q37: the work order shows its payment apart from its work stage
            if ($workOrderRef !== null) {
                foreach (\App\Models\WorkOrder::where('number', $workOrderRef)->where('property_id', $propertyId)->get() as $wo) {
                    $wo->forceFill(['paid_by' => 'trust', 'payment_status' => 'paid_from_trust'])->save();
                }
            }
        });
    }

    /** Transfer the owner's net out of trust: the same lock and balance check as {@see postExpense()} (M22). */
    public function postDisbursement(int $ownerId, Money $amount, string $date): void
    {
        DB::transaction(function () use ($ownerId, $amount, $date) {
            $this->lockOwner($ownerId);
            $this->assertAvailable(Owner::findOrFail($ownerId), $amount, 'التحويل', CarbonImmutable::parse($date));
            $this->ledger->post($date, 'owner.disbursement', 'تحويل صافي المالك', [
                ["owner_payable:{$ownerId}", $amount, null], ['trust_bank', null, $amount],
            ], null, $ownerId);
        });
    }

    /**
     * What the office may still pay out of trust for this owner: the running total of the monthly statements' net
     * (rent held by the office, less the fee when deducted, maintenance and margins, plus recovered maintenance) from
     * the first month of the relationship to the month of `$on`, less what was already transferred. Rent paid straight
     * into the owner's account is never part of it; nor are deposits or tenant credit not yet applied to an invoice.
     */
    public function availableBalance(Owner $owner, ?CarbonImmutable $on = null): Money
    {
        return $this->position($owner, $on)['available'];
    }

    /**
     * The owner's trust position, from two independent sides (M22, before merge):
     *  - the ledger: trust cash attributed to the owner (trust_bank lines carrying his id: rent held, recovered
     *    maintenance, less maintenance and transfers paid out), less what the office is owed out of it — management
     *    fees deducted from remittance and vendor margins — which stay in trust until the office takes them;
     *  - the statements: the running total of the monthly net less transfers.
     * `available` never exceeds either side and is never negative; when the office is owed more than the cash it holds,
     * the shortfall is `owner_owes`, shown apart from the cash. `difference` (ledger − statements) must be zero; a
     * non-zero value is shown on the statement page.
     *
     * @return array{trust_cash: Money, fees_owed: Money, margins_owed: Money, owed_to_office: Money, available: Money, owner_owes: Money, ledger_based: Money, statement_based: Money, difference: Money}
     */
    public function position(Owner $owner, ?CarbonImmutable $on = null): array
    {
        $last = ($on ?? \App\Support\Dates\Today::date())->startOfMonth();
        $r = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account', 'trust_bank')->where('l.owner_id', $owner->id)->where('e.entry_date', '<=', $last->endOfMonth()->toDateString())
            ->selectRaw('coalesce(sum(l.debit_minor),0) as d, coalesce(sum(l.credit_minor),0) as c')->first();
        $cash = Money::of((int) $r->d - (int) $r->c);
        $fees = Money::zero();
        $margins = Money::zero();
        $statements = Money::zero();
        $first = $this->firstMonth($owner);
        for ($m = $first; $m !== null && $m->lessThanOrEqualTo($last); $m = $m->addMonthNoOverflow()) {
            $c = $this->core($owner, $m, false);
            if ($c['management_fee']['deducted']) {
                $fees = $fees->plus($c['management_fee']['amount'])->plus($c['management_fee']['vat']);
            }
            $margins = $margins->plus($c['vendor_margin_total']);
            $statements = $statements->plus($c['net_to_owner'])->minus($this->disbursedIn($owner, $m));
        }
        $owed = $fees->plus($margins);
        $ledger = $cash->minus($owed);
        $available = $ledger->lessThan($statements) ? $ledger : $statements;
        return [
            'trust_cash' => $cash, 'fees_owed' => $fees, 'margins_owed' => $margins, 'owed_to_office' => $owed,
            'available' => $available->isPositive() ? $available : Money::zero(),
            'owner_owes' => $owed->greaterThan($cash) ? $owed->minus($cash) : Money::zero(),
            'ledger_based' => $ledger, 'statement_based' => $statements, 'difference' => $ledger->minus($statements),
        ];
    }

    /** Postgres advisory lock per owner, released at commit: two postings for one owner run one after the other. */
    public const LOCK_NAMESPACE = 22070;

    private function lockOwner(int $ownerId): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $ownerId]);
    }

    private function assertAvailable(Owner $owner, Money $need, string $whatAr, CarbonImmutable $on): void
    {
        $available = $this->availableBalance($owner, $on);
        if ($need->greaterThan($available)) {
            $fmt = fn (Money $m) => $m->toDecimalString();
            throw new FinanceException('رصيد أمانات المالك «' . $owner->name_ar . '» المتاح للصرف ' . $fmt($available) . ' لا يكفي ل' . $whatAr . ' (' . $fmt($need) . ')، فلم يُرحَّل شيء. '
                . 'يبقى الطلب معلّقاً حتى يصل تحصيل يغطيه أو يُرفض، والمبلغ الذي قبضه المالك في حسابه مباشرة لا يُصرف منه المكتب.');
        }
    }

    private function runningThrough(Owner $owner, CarbonImmutable $lastMonth): Money
    {
        $total = Money::zero();
        $first = $this->firstMonth($owner);
        for ($m = $first; $m !== null && $m->lessThanOrEqualTo($lastMonth); $m = $m->addMonthNoOverflow()) {
            $total = $total->plus($this->core($owner, $m, false)['net_to_owner'])->minus($this->disbursedIn($owner, $m));
        }
        return $total;
    }

    /** First month of trust activity for the owner: an agreement's start or the earliest ledger line attributed to them. */
    private function firstMonth(Owner $owner): ?CarbonImmutable
    {
        $dates = array_filter([
            ManagementAgreement::where('owner_id', $owner->id)->whereNull('archived_at')->min('starts_on'),
            DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where(fn ($q) => $q->where('l.owner_id', $owner->id)->orWhere('e.owner_id', $owner->id))->min('e.entry_date'),
        ]);
        return $dates ? CarbonImmutable::parse(min(array_map(fn ($d) => CarbonImmutable::parse($d)->toDateString(), $dates)))->startOfMonth() : null;
    }

    /** Net transferred to the owner in a month (reversals net out). */
    private function disbursedIn(Owner $owner, CarbonImmutable $month): Money
    {
        $r = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.account', "owner_payable:{$owner->id}")
            ->whereBetween('e.entry_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('coalesce(sum(l.debit_minor),0) as d, coalesce(sum(l.credit_minor),0) as c')->first();
        return Money::of((int) $r->d - (int) $r->c);
    }

    /**
     * The owner's monthly statement (REQ-0305, REQ-0306, REQ-0431). M22 adds the running position: what was available
     * at the start of the month, what was transferred in it, and what remains available at its end.
     */
    public function statement(Owner $owner, CarbonImmutable $month): array
    {
        $s = $this->core($owner, $month, true);
        $opening = $this->runningThrough($owner, $month->startOfMonth()->subMonthNoOverflow());
        $disbursed = $this->disbursedIn($owner, $month);
        $transfers = JournalEntry::where('owner_id', $owner->id)->where('kind', 'like', 'owner.disbursement%')
            ->whereBetween('entry_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->with('lines')->orderBy('entry_date')->get()
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'description' => $e->memo_ar,
                'amount' => ($l = $e->lines->firstWhere('account', "owner_payable:{$owner->id}")) ? $l->debit_minor->minus($l->credit_minor) : Money::zero()]);
        return $s + ['opening_available' => $opening, 'disbursed' => $disbursed, 'disbursements' => $transfers->all(),
            'available' => $opening->plus($s['net_to_owner'])->minus($disbursed)];
    }

    /** One month's net only, without the running position (lists that show a figure per owner). */
    public function monthlyNet(Owner $owner, CarbonImmutable $month): Money
    {
        return $this->core($owner, $month, true)['net_to_owner'];
    }

    private function core(Owner $owner, CarbonImmutable $month, bool $requireAgreement): array
    {
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $agreement = ManagementAgreement::where('owner_id', $owner->id)->whereNull('archived_at')
            ->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->orderByDesc('starts_on')->first();
        if (! $agreement && $requireAgreement) {
            throw new FinanceException('No management agreement covers this owner for the month.');
        }
        // (the running balance also walks months outside any agreement: their money and costs still count, with no fee)

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
            if ($ag && $ag->fee_type !== 'fixed') {
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
        // a month with no fee needs no VAT rate (the running balance walks months before the first rate on record);
        // a real fee in such a month still stops with the missing-rate error instead of guessing a rate
        $feeTreatment = $fee->isZero() ? ['code' => VatEngine::STANDARD_MANAGEMENT_FEE, 'rate_bp' => 0]
            : $this->vat->treatment('management_fee', null, $managerRegistered, 'long', $month->endOfMonth());
        $feeVat = $this->vat->tax($fee, $feeTreatment['rate_bp']);

        $maintenance = JournalEntry::where('owner_id', $owner->id)->where('kind', 'expense.maintenance')->whereBetween('entry_date', [$from, $to])->with('lines')->orderBy('entry_date')->get()
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'description' => $e->memo_ar, 'work_order' => $e->external_ref, 'amount' => $e->lines->firstWhere('account', "expense_on_behalf:{$owner->id}")->debit_minor]);
        $margins = JournalEntry::where('owner_id', $owner->id)->where('kind', 'expense.vendor_margin')->whereBetween('entry_date', [$from, $to])->with('lines')->get()
            ->map(fn ($e) => ['description' => $e->memo_ar, 'amount' => $e->lines->firstWhere('account', "fee_expense:{$owner->id}")->debit_minor]);
        // Q37 (أ): costs the owner paid the vendor himself — listed for him, never deducted from trust or from the net
        $ownerPaid = JournalEntry::where('owner_id', $owner->id)->where('kind', 'expense.maintenance.owner_paid')->whereBetween('entry_date', [$from, $to])->with('lines')->orderBy('entry_date')->get()
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'description' => $e->memo_ar, 'work_order' => $e->external_ref, 'amount' => $e->lines->firstWhere('account', "expense_on_behalf:{$owner->id}")->debit_minor]);
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
            'management_fee' => ['type' => $agreement?->fee_type ?? 'percentage', 'percentage_bp' => $agreement?->fee_percentage_bp ?? 0, 'percentage_part' => $feePct, 'fixed_part' => $feeFixed, 'amount' => $fee, 'vat' => $feeVat, 'tax_code' => $feeTreatment['code'],
                'basis' => $basis, 'base' => $base, 'fixed_period' => $period, 'collection' => $collection, 'deducted' => $deducted, 'agreements' => count($parts) > 1 ? $parts : []],
            'maintenance' => $maintenance->all(), 'maintenance_total' => $maintTotal,
            'maintenance_owner_paid' => $ownerPaid->all(), 'maintenance_owner_paid_total' => Money::sum($ownerPaid->pluck('amount')->all()),
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
        // early answer for the employee; the binding check runs again under the lock when it is approved (M22)
        $this->assertAvailable($owner, $amount, 'التحويل', $on);
        return $this->approvals->submit($maker, 'trust.disburse', ['owner_id' => $owner->id, 'amount_minor' => $amount->minor()],
            fn () => $this->postDisbursement($owner->id, $amount, $on->toDateString()), 'owner', (string) $owner->id);
    }

    /** Transfers of this owner still waiting for a checker (shown on the statement so a second one is not raised blindly). */
    public function pendingDisbursements(Owner $owner): Money
    {
        return Money::sum(ApprovalRequest::where('action', 'trust.disburse')->where('status', 'pending')->where('subject_type', 'owner')->where('subject_id', (string) $owner->id)
            ->get()->filter(fn ($r) => isset($r->payload['amount_minor']) && ! isset($r->payload['cost_minor']))->map(fn ($r) => Money::of((int) $r->payload['amount_minor']))->all());
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
