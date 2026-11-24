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
    public function record(Tenant $tenant, Money $amount, string $method, CarbonImmutable $receivedOn, string $paidTo, ?User $by = null, ?string $reference = null, string $source = 'manual'): Payment
    {
        if (! $amount->isPositive()) {
            throw new FinanceException('A payment must be positive.');
        }
        if (! in_array($paidTo, ['manager', 'owner'], true)) {
            throw new FinanceException('Payments go to the manager or the owner only — never the platform (REQ-0436).');
        }
        app(\App\Leasing\PolicyService::class)->assertOfficeCollectionAllowed($paidTo, $receivedOn);   // Q33
        return DB::transaction(function () use ($tenant, $amount, $method, $receivedOn, $paidTo, $by, $reference, $source) {
            $payment = Payment::create([
                'tenant_id' => $tenant->id, 'receipt_number' => $this->numbering->next('receipt', (int) $receivedOn->format('Y')),
                'amount_minor' => $amount, 'method' => $method, 'source' => $source, 'received_on' => $receivedOn->toDateString(),
                'reference' => $reference, 'paid_to' => $paidTo, 'recorded_by' => $by?->id,
            ]);
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
            return $payment;
        });
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
                    $this->ledger->reverse($e, CarbonImmutable::today()->toDateString(), 'عكس سند قبض ' . $payment->receipt_number);
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
                }
                $applied++;
            }
            if ($lines) {
                $this->ledger->post($on->toDateString(), 'payment.credit_applied', 'تطبيق رصيد سند ' . $payment->receipt_number, $lines, $payment);
            }
        }
        return $applied;
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
        if ($total->greaterThan($held)) {
            throw new FinanceException('Deductions exceed the deposit held.');
        }
        $payload = ['lease_id' => $lease->id, 'deductions' => array_map(fn ($d) => ['description' => $d['description'], 'amount_minor' => $d['amount']->minor(), 'document' => $d['document']], $deductions)];
        return $this->approvals->submit($maker, 'deposit.refund', $payload, function () use ($lease, $held, $total, $on) {
            $owner = $lease->unit->property->shares->first()?->owner_id;
            $this->ledger->post($on->toDateString(), 'deposit.refund', 'رد تأمين العقد ' . $lease->id, [
                ["deposit_liability:{$lease->id}", $held, null],
                ['trust_bank', null, $held->minus($total)],
                ["rent_income:$owner", null, $total],   // documented damages compensate the owner
            ], $lease, $owner);
        }, 'lease', (string) $lease->id);
    }
}
