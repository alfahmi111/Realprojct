<?php

namespace App\Approvals;

use App\Finance\FinanceException;
use App\Finance\InvoiceService;
use App\Finance\Ledger;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Executes an approved sensitive entry from its stored payload (REQ-0069). A checker approves later, from another
 * request, so the effect is rebuilt from what was persisted at submission — the same effects as the services'
 * immediate path when dual approval is disabled.
 */
class ApprovalExecutor
{
    public function __construct(private DualApproval $approvals, private Ledger $ledger, private InvoiceService $invoices)
    {
    }

    public function approve(ApprovalRequest $req, User $checker): ApprovalRequest
    {
        return $this->approvals->approve($req, $checker, fn (ApprovalRequest $r) => $this->execute($r));
    }

    public function reject(ApprovalRequest $req, User $checker, string $reason): ApprovalRequest
    {
        $done = $this->approvals->reject($req, $checker, $reason);
        // Q37: a refused payment from trust leaves the work order «payment refused» so the owner can pay the vendor himself
        if ($req->action === 'trust.disburse' && ! empty($req->payload['work_order'])) {
            foreach (\App\Models\WorkOrder::where('number', $req->payload['work_order'])->where('property_id', (int) ($req->payload['property_id'] ?? 0))->where('payment_status', 'awaiting_trust_approval')->get() as $wo) {
                $wo->forceFill(['payment_status' => 'trust_rejected'])->save();
            }
        }
        return $done;
    }

    public function execute(ApprovalRequest $r): void
    {
        $p = $r->payload;
        $today = \App\Support\Dates\Today::date()->toDateString();
        DB::transaction(function () use ($r, $p, $today) {
            match ($r->action) {
                'invoice.cancel' => $this->cancelInvoice(Invoice::findOrFail($p['invoice_id'])),
                'payment.edit' => $this->reversePayment(Payment::findOrFail($p['payment_id']), $today),
                'trust.disburse' => isset($p['cost_minor']) ? $this->expense($p, $today) : $this->disburse($p, $today),
                'deposit.refund' => $this->depositRefund($p, $today),
                default => throw new ApprovalException("No executor for [{$r->action}]."),
            };
        });
    }

    /** Re-check at approval time (review M18): a payment may have been allocated to the invoice while it waited. */
    private function cancelInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== 'issued' || $this->invoices->hasLiveAllocations($invoice)) {
            throw new FinanceException('تغيّرت الفاتورة منذ الطلب (سُددت أو أُلغيت)؛ لا يمكن إلغاؤها الآن.');
        }
        $this->invoices->executeCancellation($invoice);
    }

    private function reversePayment(Payment $payment, string $date): void
    {
        if ($payment->status !== 'recorded') {
            throw new FinanceException('الدفعة معكوسة من قبل.');   // never reverse twice (review M18)
        }
        $payment->forceFill(['status' => 'reversed'])->save();
        foreach (JournalEntry::where('source_type', 'Payment')->where('source_id', (string) $payment->id)->get() as $e) {
            $this->ledger->reverse($e, $date, 'عكس سند قبض ' . $payment->receipt_number);
        }
    }

    /** Same path as an unreviewed posting: owner lock + available-balance check + entries, in one transaction (M22). */
    private function expense(array $p, string $date): void
    {
        $margin = ($p['margin_minor'] ?? 0) > 0 ? Money::of((int) $p['margin_minor']) : null;
        app(\App\Finance\TrustAccounting::class)->postExpense((int) $p['owner_id'], (int) $p['property_id'], Money::of((int) $p['cost_minor']), $margin, $p['description'], $p['work_order'] ?? null, $date);
    }

    private function disburse(array $p, string $date): void
    {
        app(\App\Finance\TrustAccounting::class)->postDisbursement((int) $p['owner_id'], Money::of((int) $p['amount_minor']), $date);
    }

    private function depositRefund(array $p, string $date): void
    {
        $lease = Lease::with('unit.property.shares')->findOrFail($p['lease_id']);
        $total = Money::sum(array_map(fn ($d) => Money::of((int) $d['amount_minor']), $p['deductions']));
        // M22: the same locked path as a direct posting — what is still held is re-read under the lease's lock
        app(\App\Finance\PaymentService::class)->postDepositRefund($lease, $total, $date);
    }
}
