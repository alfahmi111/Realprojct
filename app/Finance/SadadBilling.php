<?php

namespace App\Finance;

use App\Integrations\IntegrationNotConfigured;
use App\Integrations\IntegrationStatus;
use App\Integrations\Sadad\SadadGateway;
use App\Models\Invoice;

/**
 * SADAD bill number for an issued invoice with an outstanding balance (REQ-0467, owner order Q30). The tenant pays it
 * from any bank app into the biller account of the office or owner; the platform never receives money (REQ-0436).
 * One bill per invoice; the payment itself is recorded as usual when the biller notifies or the manager records it.
 */
final class SadadBilling
{
    public function __construct(private SadadGateway $gateway, private InvoiceService $invoices)
    {
    }

    public function available(): bool
    {
        return in_array(IntegrationStatus::driver('sadad'), ['simulator', 'live'], true);
    }

    public function issue(Invoice $invoice): Invoice
    {
        if (! $this->available()) {
            throw new IntegrationNotConfigured('لم يُربط مزوّد سداد بعد.');
        }
        if ($invoice->status !== 'issued') {
            throw new \RuntimeException('يُصدر رقم سداد لفاتورة صادرة فقط.');
        }
        if ($invoice->sadad_bill_number) {
            return $invoice;
        }
        $due = $this->invoices->outstanding($invoice);
        if (! $due->isPositive()) {
            throw new \RuntimeException('الفاتورة مسددة.');
        }
        $r = $this->gateway->issueBill(['number' => $invoice->number, 'amount_minor' => $due->minor(), 'due_on' => (string) ($invoice->period_start ?? $invoice->issue_date), 'customer' => (string) $invoice->tenant?->name_ar]);
        $invoice->forceFill(['sadad_bill_number' => $r['bill_number'], 'sadad_issued_at' => now(), 'sadad_amount_minor' => $due->minor()])->save();
        return $invoice;
    }

    /**
     * Keep a SADAD bill equal to what is still due (review M18 F12): after a partial payment by another channel (or a
     * reversal) the bill is reissued for the new balance; a settled invoice keeps its old number for the record.
     */
    public function sync(Invoice $invoice): Invoice
    {
        if (! $invoice->sadad_bill_number || $invoice->status !== 'issued' || ! $this->available()) {
            return $invoice;
        }
        $due = $this->invoices->outstanding($invoice);
        if (! $due->isPositive() || $due->minor() === (int) $invoice->sadad_amount_minor) {
            return $invoice;
        }
        $invoice->forceFill(['sadad_bill_number' => null])->save();
        return $this->issue($invoice);
    }

    public function syncTenant(\App\Models\Tenant $tenant): void
    {
        if (! $this->available()) {
            return;
        }
        foreach (Invoice::where('tenant_id', $tenant->id)->where('status', 'issued')->whereNotNull('sadad_bill_number')->get() as $i) {
            $this->sync($i);
        }
    }
}
