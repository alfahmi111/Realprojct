<?php

namespace App\Finance;

use App\Approvals\DualApproval;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payments and receipts (REQ-0316 "تسجيل دفعة وإصدار سند قبض", REQ-0188 FIFO, REQ-0436 money goes directly to the
 * manager or owner). The platform only records and matches.
 */
class PaymentService
{
    public function __construct(private Ledger $ledger, private Numbering $numbering, private InvoiceService $invoices, private DualApproval $approvals)
    {
    }

    /**
     * Record a payment and allocate it to the tenant's open invoices, oldest first (FIFO) by default (REQ-0188).
     * Any remainder stays as tenant credit and shows in reconciliation as an unallocated payment.
     */
    /** Electronic methods: their collection is confirmed only by the transaction's reference (owner decision Q38). */
    public const ELECTRONIC = ['mada', 'apple_pay', 'card', 'sadad', 'bank_transfer'];

    /** Who issued the reference — the scope of its uniqueness (Q38). */
    public const SOURCES = ['ejar' => 'منصة إيجار', 'sadad' => 'سداد', 'bank' => 'البنك (مرجع الحوالة)', 'card' => 'بوابة الدفع أو نقطة البيع'];

    /** Sources a method can come through: Ejar collects by mada, card or SADAD; a transfer carries the bank's reference. */
    public const SOURCES_BY_METHOD = ['mada' => ['ejar', 'card'], 'apple_pay' => ['ejar', 'card'], 'card' => ['ejar', 'card'], 'sadad' => ['ejar', 'sadad'], 'bank_transfer' => ['bank']];

    public function record(Tenant $tenant, Money $amount, string $method, CarbonImmutable $receivedOn, string $paidTo, ?User $by = null, ?string $reference = null, string $source = 'manual', ?string $refSource = null): Payment
    {
        if (! $amount->isPositive()) {
            throw new FinanceException('A payment must be positive.');
        }
        if (! in_array($paidTo, ['manager', 'owner'], true)) {
            throw new FinanceException('Payments go to the manager or the owner only — never the platform (REQ-0436).');
        }
        app(\App\Leasing\PolicyService::class)->assertOfficeCollectionAllowed($paidTo, $receivedOn);   // Q33
        $electronic = in_array($method, self::ELECTRONIC, true);
        $refSource = $electronic ? self::resolveSource($method, $refSource) : null;
        $key = $electronic ? self::externalKey($reference) : null;
        if ($electronic && ($key === null || $refSource === null)) {
            // Q38: without its complete reference an electronic collection is not confirmed — it waits for matching with
            // no receipt, no allocation and no ledger entry, so nothing financial is built on it
            $p = new Payment(['tenant_id' => $tenant->id, 'amount_minor' => $amount, 'method' => $method, 'source' => $source, 'received_on' => $receivedOn->toDateString(),
                'reference' => $reference, 'ref_source' => $refSource, 'paid_to' => $paidTo, 'recorded_by' => $by?->id]);
            $p->forceFill(['status' => 'pending_match'])->save();
            return $p;
        }
        // M22/Q38: the same external transaction is recorded once per source, whatever tenant or receiving account is picked
        $this->assertNotRecorded($key, $refSource);
        $payment = $this->guardUnique($key, $refSource, fn () => DB::transaction(fn () => $this->store($tenant, $amount, $method, $receivedOn, $paidTo, $by, $reference, $source, $key, $refSource)));
        app(SadadBilling::class)->syncTenant($tenant);   // a SADAD bill follows what is still due (review M18 F12)
        return $payment;
    }

    /**
     * Confirm a payment waiting for matching once its reference is known (Q38): it gets its receipt number, is
     * allocated FIFO and posted, dated the day it was received.
     */
    public function confirmMatch(Payment $payment, string $reference, ?string $refSource, ?User $by = null): Payment
    {
        if ($payment->status !== 'pending_match') {
            throw new FinanceException('هذه الدفعة ليست بانتظار المطابقة.');
        }
        $refSource = self::resolveSource($payment->method, $refSource);
        $key = self::externalKey($reference);
        if ($key === null || $refSource === null) {
            throw new FinanceException('أدخل رقم العملية ومصدره كاملين لتأكيد الدفعة.');
        }
        $receivedOn = CarbonImmutable::parse($payment->received_on);
        app(\App\Leasing\PolicyService::class)->assertOfficeCollectionAllowed($payment->paid_to, $receivedOn);
        $this->assertNotRecorded($key, $refSource);
        $done = $this->guardUnique($key, $refSource, fn () => DB::transaction(function () use ($payment, $reference, $refSource, $key, $by, $receivedOn) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'pending_match') {
                throw new FinanceException('هذه الدفعة أُكّدت من قبل؛ حدّث الصفحة.');
            }
            $p->forceFill(['reference' => $reference, 'ref_source' => $refSource, 'external_key' => $key, 'status' => 'recorded',
                'receipt_number' => $this->numbering->next('receipt', (int) $receivedOn->format('Y')), 'recorded_by' => $by?->id ?? $p->recorded_by])->save();
            $this->allocateAndPost($p);
            return $p;
        }));
        app(SadadBilling::class)->syncTenant($done->tenant);
        return $done;
    }

    /** Drop a payment that waits for matching and was entered by mistake: it never had a financial effect (Q38). */
    public function discardPending(Payment $payment, User $by, string $reason): void
    {
        if ($payment->status !== 'pending_match') {
            throw new FinanceException('تُلغى من هنا الدفعة التي بانتظار المطابقة فقط؛ الدفعة المسجّلة تُعكس بالاعتماد المزدوج.');
        }
        $payment->forceFill(['status' => 'reversed'])->save();
        \App\Audit\AuditLogger::record('payment.pending_discarded', $payment, ['status' => 'pending_match'], ['status' => 'reversed', 'by' => $by->id, 'reason' => $reason]);
    }

    /** The source a reference belongs to: the one chosen, or the method's only source; a source the method cannot use is refused. */
    public static function resolveSource(string $method, ?string $refSource): ?string
    {
        $allowed = self::SOURCES_BY_METHOD[$method] ?? [];
        if ($refSource === null || $refSource === '') {
            return count($allowed) === 1 ? $allowed[0] : null;
        }
        if (! in_array($refSource, $allowed, true)) {
            throw new FinanceException('مصدر المرجع «' . (self::SOURCES[$refSource] ?? $refSource) . '» لا يناسب وسيلة الدفع المختارة.');
        }
        return $refSource;
    }

    /** Two requests that passed the check together: the unique index lets one through; the other gets the clear message. */
    private function guardUnique(?string $key, ?string $refSource, callable $write): Payment
    {
        try {
            return $write();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($key !== null && $e->getCode() === '23505' && str_contains($e->getMessage(), 'payments_external_ref_unique')) {
                $this->assertNotRecorded($key, $refSource);
            }
            throw $e;
        }
    }

    /**
     * The external transaction's identity: its reference (transfer number, Ejar or SADAD operation, gateway id) with
     * spaces, dashes and case removed and Arabic digits read as Latin, so «EJR-٧٧٨٩٠١» and «ejr 778901» are the same
     * transaction. No reference → no key: such a payment cannot be matched to an outside movement (cash, as a rule).
     */
    public static function externalKey(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $s = strtr($reference, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($s)) ?? '';
        return $s === '' ? null : mb_substr($s, 0, 200);
    }

    /** Refuse a transaction already recorded (and not reversed) from the same source — in any account, for any tenant. */
    private function assertNotRecorded(?string $key, ?string $refSource): void
    {
        if ($key === null || $refSource === null) {
            return;
        }
        $existing = Payment::where('external_key', $key)->where('ref_source', $refSource)->where('status', 'recorded')->first();
        if ($existing) {
            throw new DuplicatePaymentException('هذه الحركة مسجّلة من قبل بسند القبض ' . $existing->receipt_number . ' بتاريخ ' . $existing->received_on->toDateString()
                . ' (رقم العملية «' . $existing->reference . '» من ' . (self::SOURCES[$refSource] ?? $refSource) . ' في ' . ($existing->paid_to === 'owner' ? 'حساب المالك' : 'حساب أمانات المكتب')
                . '). لا تُسجَّل الحركة نفسها مرتين ولو اختير مستأجر أو حساب آخر؛ وإن كانت دفعة أخرى فأدخل رقم عمليتها هي.');
        }
    }

    private function store(Tenant $tenant, Money $amount, string $method, CarbonImmutable $receivedOn, string $paidTo, ?User $by, ?string $reference, string $source, ?string $key, ?string $refSource): Payment
    {
        // receipt, FIFO allocation (REQ-0188) and the ledger entry, inside the caller's transaction
        $payment = Payment::create([
            'tenant_id' => $tenant->id, 'receipt_number' => $this->numbering->next('receipt', (int) $receivedOn->format('Y')),
            'amount_minor' => $amount, 'method' => $method, 'source' => $source, 'received_on' => $receivedOn->toDateString(),
            'reference' => $reference, 'external_key' => $key, 'ref_source' => $refSource, 'paid_to' => $paidTo, 'recorded_by' => $by?->id,
        ]);
        $this->allocateAndPost($payment);
        return $payment;
    }

    /** FIFO allocation to the tenant's open invoices (REQ-0188) and the receipt's ledger entry; the rest is tenant credit. */
    private function allocateAndPost(Payment $payment): void
    {
        $tenant = $payment->tenant;
        $amount = $payment->amount_minor;
        $paidTo = $payment->paid_to;
        $receivedOn = CarbonImmutable::parse($payment->received_on);
        $left = $amount;
        $open = Invoice::where('tenant_id', $tenant->id)->where('status', 'issued')->with('allocations')
            ->orderBy('period_start')->orderBy('issue_date')->orderBy('id')->get();
        $lines = [];
        foreach ($open as $inv) {
            if ($left->isZero()) {
                break;
            }
            $due = $this->invoices->outstanding($inv);
            if (! $due->isPositive()) {
                continue;
            }
            $take = Money::min($due, $left);
            PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $inv->id, 'amount_minor' => $take]);
            $left = $left->minus($take);
            $owner = $inv->seller_owner_id;
            $lines[] = [$paidTo === 'owner' ? "owner_direct:$owner" : 'trust_bank', $take, null, $owner];
            $lines[] = ["receivable:$owner", null, $take, $owner];
        }
        if ($left->isPositive()) {
            $lines[] = [$paidTo === 'owner' ? 'owner_direct:unassigned' : 'trust_bank', $left, null];
            $lines[] = ["tenant_credit:{$tenant->id}", null, $left];
        }
        $this->ledger->post($receivedOn->toDateString(), 'payment', 'سند قبض ' . $payment->receipt_number, $lines, $payment);
    }

    /** Editing a recorded payment is a sensitive entry: maker/checker, executed as a reversal (REQ-0069). */
    public function requestReversal(Payment $payment, User $maker, string $reason): ApprovalRequest
    {
        if ($payment->status !== 'recorded') {
            throw new FinanceException('الدفعة معكوسة من قبل.');
        }
        $this->approvals->assertNonePending('payment', (string) $payment->id);   // review M18
        return $this->approvals->submit($maker, 'payment.edit', ['payment_id' => $payment->id, 'reason' => $reason], function () use ($payment) {
            DB::transaction(function () use ($payment) {
                $payment->forceFill(['status' => 'reversed'])->save();
                foreach (\App\Models\JournalEntry::where('source_type', 'Payment')->where('source_id', (string) $payment->id)->get() as $e) {
                    $this->ledger->reverse($e, \App\Support\Dates\Today::date()->toDateString(), 'عكس سند قبض ' . $payment->receipt_number);
                }
                // Allocations are append-only; those of a reversed payment no longer count (InvoiceService::outstanding).
            });
        }, 'payment', (string) $payment->id);
    }

    /**
     * Apply a tenant's unallocated credit to his open invoices, oldest first (review M18: a payment received before its
     * invoice stayed as credit forever while the invoice showed as unpaid and overdue). Ledger: the credit moves from
     * tenant_credit to the owner's receivable; money received straight by the owner moves from unassigned to that owner.
     */
    public function applyCredit(Tenant $tenant, CarbonImmutable $on): int
    {
        $applied = 0;
        foreach (Payment::where('tenant_id', $tenant->id)->where('status', 'recorded')->with('allocations')->orderBy('received_on')->orderBy('id')->get() as $payment) {
            $left = $payment->amount_minor->minus(Money::sum($payment->allocations->map(fn ($a) => $a->amount_minor)->all()));
            if (! $left->isPositive()) {
                continue;
            }
            $lines = [];
            foreach (Invoice::where('tenant_id', $tenant->id)->where('status', 'issued')->with('allocations')->orderBy('period_start')->orderBy('issue_date')->orderBy('id')->get() as $inv) {
                if ($left->isZero()) {
                    break;
                }
                $due = $this->invoices->outstanding($inv);
                if (! $due->isPositive()) {
                    continue;
                }
                $take = Money::min($due, $left);
                PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $inv->id, 'amount_minor' => $take]);
                $left = $left->minus($take);
                $owner = $inv->seller_owner_id;
                $lines[] = ["tenant_credit:{$tenant->id}", $take, null];
                $lines[] = ["receivable:$owner", null, $take, $owner];
                if ($payment->paid_to === 'owner') {
                    $lines[] = ["owner_direct:$owner", $take, null, $owner];
                    $lines[] = ['owner_direct:unassigned', null, $take];
                } else {
                    // M22: the cash already sits in trust as the tenant's credit; it now belongs to this owner's trust
                    // position (same account, the line's owner changes), so the owner's trust ledger matches his statement
                    $lines[] = ['trust_bank', $take, null, $owner];
                    $lines[] = ['trust_bank', null, $take, null];
                }
                $applied++;
            }
            if ($lines) {
                $this->ledger->post($on->toDateString(), 'payment.credit_applied', 'تطبيق رصيد سند ' . $payment->receipt_number, $lines, $payment);
            }
        }
        return $applied;
    }

    /**
     * Where a lease's deposit sits: the office trust account, or the owner's own account (Q33). The refund is paid from
     * the same place, so a deposit kept by the owner never leaves the office trust account (review M18 F12).
     */
    public function depositAccount(Lease $lease): string
    {
        $held = \Illuminate\Support\Facades\DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.kind', 'deposit.received')->where('e.source_type', class_basename($lease))->where('e.source_id', $lease->id)
            ->whereNotNull('l.debit_minor')->where('l.debit_minor', '>', 0)->orderByDesc('e.id')->value('l.account');
        return $held === 'owner_direct:deposit' ? 'owner_direct:deposit' : 'trust_bank';
    }

    /**
     * Refund entry: the liability closes; the tenant is paid from where the deposit sits; documented damages go to the
     * owner. M22: every line names its owner explicitly — the deposit's cash is nobody's trust money (null), and only the
     * deducted part, kept in trust, becomes the owner's (same account, owner changes). Before, the refund line took the
     * entry's owner and reduced the owner's trust position by money that was never his.
     */
    public function depositRefundLines(Lease $lease, Money $held, Money $deductions, ?int $owner): array
    {
        $account = $this->depositAccount($lease);
        $refund = $held->minus($deductions);
        return array_values(array_filter([
            ["deposit_liability:{$lease->id}", $held, null, null],
            $refund->isPositive() ? [$account, null, $refund, null] : null,
            $deductions->isPositive() ? ["rent_income:$owner", null, $deductions, $owner] : null,
            $deductions->isPositive() && $account === 'trust_bank' ? ['trust_bank', $deductions, null, $owner] : null,
            $deductions->isPositive() && $account === 'trust_bank' ? ['trust_bank', null, $deductions, null] : null,
        ]));
    }

    /** Postgres advisory lock per lease for its deposit (released at commit), next to the per-owner trust lock. */
    public const DEPOSIT_LOCK_NAMESPACE = 22071;

    /**
     * Post a deposit refund once (M22): under the lease's lock, re-read what is still held — a second request (or two
     * approved together) finds nothing left and is refused instead of paying the deposit twice.
     */
    public function postDepositRefund(Lease $lease, Money $deductions, string $date): void
    {
        DB::transaction(function () use ($lease, $deductions, $date) {
            DB::select('select pg_advisory_xact_lock(?, ?)', [self::DEPOSIT_LOCK_NAMESPACE, $lease->id]);
            $held = $this->ledger->balance("deposit_liability:{$lease->id}")->negate();
            if (! $held->isPositive()) {
                throw new FinanceException('لا يوجد تأمين محفوظ لهذا العقد؛ رُدّ من قبل أو لم يُسجَّل. لم يُرحَّل شيء.');
            }
            if ($deductions->greaterThan($held)) {
                throw new FinanceException('الخصومات أكبر من التأمين المحفوظ.');
            }
            $owner = $this->depositOwner($lease, $deductions);
            $this->ledger->post($date, 'deposit.refund', 'رد تأمين العقد ' . $lease->id, $this->depositRefundLines($lease, $held, $deductions, $owner), $lease, $owner);
        });
    }

    /** Deposit received: a liability, never revenue (REQ-0189, REQ-0433). */
    public function recordDeposit(Lease $lease, Money $amount, CarbonImmutable $on, string $paidTo = 'owner'): void
    {
        app(\App\Leasing\PolicyService::class)->assertOfficeCollectionAllowed($paidTo, $on);   // Q33
        $this->ledger->post($on->toDateString(), 'deposit.received', 'تأمين العقد ' . $lease->id, [
            [$paidTo === 'owner' ? 'owner_direct:deposit' : 'trust_bank', $amount, null],
            ["deposit_liability:{$lease->id}", null, $amount],
        ], $lease);
    }

    /**
     * Deposit refund at settlement after documented damage deductions (REQ-0189) — maker/checker (REQ-0069).
     * $deductions = [['description' => ..., 'amount' => Money, 'document' => path], ...]
     */
    public function requestDepositRefund(Lease $lease, User $maker, array $deductions, CarbonImmutable $on): ApprovalRequest
    {
        $held = $this->ledger->balance("deposit_liability:{$lease->id}")->negate();
        $total = Money::sum(array_column($deductions, 'amount'));
        foreach ($deductions as $d) {
            if (empty($d['document'])) {
                throw new FinanceException('Every deduction from the deposit needs a supporting document.');
            }
        }
        if (! $held->isPositive()) {
            throw new FinanceException('لا يوجد تأمين محفوظ لهذا العقد؛ رُدّ من قبل أو لم يُسجَّل.');
        }
        if ($total->greaterThan($held)) {
            throw new FinanceException('Deductions exceed the deposit held.');
        }
        $this->depositOwner($lease, $total);   // M22: refuse before the request exists, with the reason
        $this->approvals->assertNonePending('lease', (string) $lease->id);   // one refund request at a time
        $payload = ['lease_id' => $lease->id, 'deductions' => array_map(fn ($d) => ['description' => $d['description'], 'amount_minor' => $d['amount']->minor(), 'document' => $d['document']], $deductions)];
        return $this->approvals->submit($maker, 'deposit.refund', $payload, fn () => $this->postDepositRefund($lease, $total, $on->toDateString()), 'lease', (string) $lease->id);
    }

    /**
     * The owner credited with deposit deductions (M22): the property's only owner. With several owners, deductions wait
     * for the accountant's rule instead of going to the first owner on file; a full refund credits nobody (null).
     */
    public function depositOwner(Lease $lease, Money $deductions): ?int
    {
        $propertyId = (int) $lease->unit->property_id;
        if ($deductions->isPositive()) {
            return SoleOwner::of($propertyId, 'خصومات التأمين')->id;
        }
        return SoleOwner::shareCount($propertyId) === 1 ? (int) \App\Models\OwnershipShare::where('property_id', $propertyId)->value('owner_id') : null;
    }
}
