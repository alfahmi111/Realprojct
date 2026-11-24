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
        return $this->approvals->reject($req, $checker, $reason);
    }

    public function execute(ApprovalRequest $r): void
    {
        $p = $r->payload;
        $today = CarbonImmutable::today()->toDateString();
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

    private function expense(array $p, string $date): void
    {
        $cost = Money::of((int) $p['cost_minor']);
        $ref = $p['work_order'] ?? null;
        $this->ledger->post($date, 'expense.maintenance', $p['description'] . ($ref ? " ($ref)" : ''), [
            ["expense_on_behalf:{$p['owner_id']}", $cost, null], ['trust_bank', null, $cost],
        ], null, (int) $p['owner_id'], (int) $p['property_id'], $ref);
        if (($p['margin_minor'] ?? 0) > 0) {
            $m = Money::of((int) $p['margin_minor']);
            $this->ledger->post($date, 'expense.vendor_margin', 'هامش المدير على فاتورة المورّد' . ($ref ? " ($ref)" : ''), [
                ["fee_expense:{$p['owner_id']}", $m, null], ['manager_revenue', null, $m],
            ], null, (int) $p['owner_id'], (int) $p['property_id']);
        }
    }

    private function disburse(array $p, string $date): void
    {
        $amount = Money::of((int) $p['amount_minor']);
        $this->ledger->post($date, 'owner.disbursement', 'تحويل صافي المالك', [
            ["owner_payable:{$p['owner_id']}", $amount, null], ['trust_bank', null, $amount],
        ], null, (int) $p['owner_id']);
    }

    private function depositRefund(array $p, string $date): void
    {
        $lease = Lease::with('unit.property.shares')->findOrFail($p['lease_id']);
        $held = $this->ledger->balance("deposit_liability:{$lease->id}")->negate();
        $total = Money::sum(array_map(fn ($d) => Money::of((int) $d['amount_minor']), $p['deductions']));
        if ($total->greaterThan($held)) {
            throw new FinanceException('Deductions exceed the deposit held.');
        }
        $owner = $lease->unit->property->shares->first()?->owner_id;
        $this->ledger->post($date, 'deposit.refund', 'رد تأمين العقد ' . $lease->id, [
            ["deposit_liability:{$lease->id}", $held, null], ['trust_bank', null, $held->minus($total)], ["rent_income:$owner", null, $total],
        ], $lease, $owner);
    }
}
