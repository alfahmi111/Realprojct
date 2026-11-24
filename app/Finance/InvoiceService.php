<?php

namespace App\Finance;

use App\Approvals\DualApproval;
use App\Integrations\EInvoicing\EInvoicingGateway;
use App\Integrations\IntegrationNotConfigured;
use App\Leasing\LeasingService;
use App\Models\ApprovalRequest;
use App\Models\BillingScheduleLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\User;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invoicing (spec §9.1–§9.2, §7.2 "إصدار الفواتير للمستفيد النهائي"):
 *  - rent invoices only for registered/active leases (REQ-0185);
 *  - every invoice carries the Ejar contract number, unit, period, buyer VAT number and national address (REQ-0424);
 *  - e-invoices go through the certified provider layer (REQ-0423, decision 5); while no provider is chosen the
 *    invoice stays "pending" for the provider — BLOCKED for production (REQ-0559);
 *  - cancellation is a sensitive entry under maker/checker (REQ-0069).
 * Rent invoices are issued in the lessor (owner) name; who issues for multi-owner properties awaits the tax opinion
 * (owner task REQ-0561) — NEEDS-PRO-REVIEW.
 */
class InvoiceService
{
    public function __construct(
        private VatEngine $vat,
        private Numbering $numbering,
        private Ledger $ledger,
        private LeasingService $leasing,
        private EInvoicingGateway $einvoicing,
        private Settings $settings,
        private DualApproval $approvals,
    ) {
    }

    public function issueRent(BillingScheduleLine $line, ?CarbonImmutable $issueDate = null): Invoice
    {
        $lease = Lease::with(['unit.property.shares.owner', 'tenant'])->findOrFail($line->lease_id);
        if (! $this->leasing->canInvoice($lease)) {
            throw new FinanceException('Rent invoices are issued only for registered or active leases.');
        }
        if ($line->status !== 'scheduled') {
            throw new FinanceException('This schedule line is already invoiced.');
        }
        $owner = $this->singleOwner($lease);
        $issueDate ??= \App\Support\Dates\Today::date();
        $t = $this->vat->treatment('rent', $lease->usage, $owner->vat_registered, $lease->track, $issueDate);

        return DB::transaction(function () use ($line, $lease, $owner, $issueDate, $t) {
            $net = $line->amount_minor;
            $tax = $this->vat->tax($net, $t['rate_bp']);
            $tenant = $lease->tenant;
            $invoice = Invoice::create([
                'number' => $this->numbering->next('invoice', (int) $issueDate->format('Y')),
                'kind' => 'rent', 'seller_type' => 'owner', 'seller_owner_id' => $owner->id, 'tenant_id' => $tenant->id,
                'lease_id' => $lease->id, 'billing_schedule_line_id' => $line->id, 'property_id' => $lease->unit->property_id,
                'issue_date' => $issueDate->toDateString(), 'period_start' => $line->period_start, 'period_end' => $line->period_end,
                'ejar_contract_number' => $lease->ejar_contract_number, 'unit_code' => $lease->unit->code,
                'buyer_vat_number' => $tenant->vat_number, 'buyer_national_address' => $tenant->national_address,
                'einvoice_type' => $tenant->kind === 'company' && $tenant->vat_number ? 'b2b' : 'b2c',
                'subtotal_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $net->plus($tax),
            ]);
            InvoiceLine::create([
                'invoice_id' => $invoice->id, 'description_ar' => 'إيجار الوحدة ' . $lease->unit->code . ' من ' . $line->period_start->toDateString() . ' إلى ' . $line->period_end->toDateString(),
                'net_minor' => $net, 'tax_code' => $t['code'], 'tax_rate_bp' => $t['rate_bp'], 'tax_minor' => $tax, 'total_minor' => $net->plus($tax),
            ]);
            $line->forceFill(['status' => 'invoiced'])->save();
            // Rent invoiced in advance is deferred and recognised monthly (REQ-0432).
            $this->ledger->post($issueDate->toDateString(), 'invoice.rent', 'فاتورة إيجار ' . $invoice->number, [
                ["receivable:{$owner->id}", $invoice->total_minor, null],
                ["deferred_rent:{$owner->id}", null, $net],
                ["vat_payable:owner:{$owner->id}", null, $tax],
            ], $invoice, $owner->id, $invoice->property_id);
            $this->submitEInvoice($invoice);
            app(PaymentService::class)->applyCredit($lease->tenant, $issueDate);   // earlier payments settle it (review M18)
            return $invoice;
        });
    }

    /** Send to the certified provider; a missing provider leaves it pending (never silently "cleared"). */
    public function submitEInvoice(Invoice $invoice): void
    {
        try {
            $r = $this->einvoicing->submit($this->payload($invoice));
            $invoice->forceFill(['einvoice_uuid' => $r['uuid'], 'einvoice_status' => $r['status'], 'einvoice_qr' => $r['qr']])->save();
        } catch (IntegrationNotConfigured) {
            $invoice->forceFill(['einvoice_status' => 'pending'])->save();
        }
    }

    /** E-invoice payload: Gregorian date as ZATCA requires (REQ-0351) and the fields of REQ-0424. */
    public function payload(Invoice $invoice): array
    {
        return [
            'type' => $invoice->einvoice_type, 'number' => $invoice->number, 'issue_date' => $invoice->issue_date->toDateString(),
            'seller_owner_id' => $invoice->seller_owner_id, 'buyer_vat_number' => $invoice->buyer_vat_number,
            'buyer_national_address' => $invoice->buyer_national_address, 'ejar_contract_number' => $invoice->ejar_contract_number,
            'unit' => $invoice->unit_code, 'period' => [$invoice->period_start?->toDateString(), $invoice->period_end?->toDateString()],
            'lines' => $invoice->lines->map(fn ($l) => ['description' => $l->description_ar, 'net' => $l->net_minor->toDecimalString(), 'tax_code' => $l->tax_code, 'tax_rate_bp' => $l->tax_rate_bp, 'tax' => $l->tax_minor->toDecimalString()])->all(),
            'total' => $invoice->total_minor->toDecimalString(),
        ];
    }

    /** Maintenance recharged to a tenant (REQ-0417, REQ-0232) — 15% on the service when the issuer is registered. */
    public function issueRecharge(Lease $lease, string $descriptionAr, Money $net, CarbonImmutable $issueDate): Invoice
    {
        $owner = $this->singleOwner($lease);
        $t = $this->vat->treatment('maintenance_recharge', null, $owner->vat_registered, 'long', $issueDate);
        return DB::transaction(function () use ($lease, $owner, $descriptionAr, $net, $issueDate, $t) {
            $tax = $this->vat->tax($net, $t['rate_bp']);
            $inv = Invoice::create([
                'number' => $this->numbering->next('invoice', (int) $issueDate->format('Y')), 'kind' => 'maintenance_recharge', 'seller_type' => 'owner',
                'seller_owner_id' => $owner->id, 'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'property_id' => $lease->unit->property_id,
                'issue_date' => $issueDate->toDateString(), 'ejar_contract_number' => $lease->ejar_contract_number, 'unit_code' => $lease->unit->code,
                'buyer_vat_number' => $lease->tenant->vat_number, 'buyer_national_address' => $lease->tenant->national_address,
                'einvoice_type' => $lease->tenant->kind === 'company' && $lease->tenant->vat_number ? 'b2b' : 'b2c',
                'subtotal_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $net->plus($tax),
            ]);
            InvoiceLine::create(['invoice_id' => $inv->id, 'description_ar' => $descriptionAr, 'net_minor' => $net, 'tax_code' => $t['code'], 'tax_rate_bp' => $t['rate_bp'], 'tax_minor' => $tax, 'total_minor' => $net->plus($tax)]);
            $this->ledger->post($issueDate->toDateString(), 'invoice.recharge', 'فاتورة صيانة ' . $inv->number, [
                ["receivable:{$owner->id}", $inv->total_minor, null], ["expense_on_behalf:{$owner->id}", null, $net], ["vat_payable:owner:{$owner->id}", null, $tax],
            ], $inv, $owner->id, $inv->property_id);
            $this->submitEInvoice($inv);
            app(PaymentService::class)->applyCredit($lease->tenant, $issueDate);   // review M18
            return $inv;
        });
    }

    /** Cancel an invoice — maker/checker (REQ-0069). Executes a reversing journal entry when approved. */
    public function requestCancellation(Invoice $invoice, User $maker, string $reason): ApprovalRequest
    {
        if ($invoice->status !== 'issued') {
            throw new FinanceException('Only issued invoices can be cancelled.');
        }
        if ($this->hasLiveAllocations($invoice)) {   // allocations of reversed payments do not count (review M18)
            throw new FinanceException('لا تُلغى فاتورة عليها دفعات مسجّلة؛ اعكس الدفعة أولاً.');
        }
        $this->approvals->assertNonePending('invoice', (string) $invoice->id);
        return $this->approvals->submit($maker, 'invoice.cancel', ['invoice_id' => $invoice->id, 'reason' => $reason], fn () => $this->executeCancellation($invoice), 'invoice', (string) $invoice->id);
    }

    public function executeCancellation(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->forceFill(['status' => 'cancelled'])->save();
            foreach (JournalEntry::where('source_type', 'Invoice')->where('source_id', (string) $invoice->id)->get() as $e) {
                $this->ledger->reverse($e, \App\Support\Dates\Today::date()->toDateString(), 'إلغاء فاتورة ' . $invoice->number);
            }
            if ($invoice->billing_schedule_line_id) {
                BillingScheduleLine::whereKey($invoice->billing_schedule_line_id)->update(['status' => 'void']);
            }
        });
    }

    /** Amount still due on an invoice. */
    public function outstanding(Invoice $invoice): Money
    {
        $live = \App\Models\PaymentAllocation::where('invoice_id', $invoice->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))->get();
        $paid = Money::sum($live->map(fn ($a) => $a->amount_minor)->all());
        return $invoice->total_minor->minus($paid);
    }

    private function singleOwner(Lease $lease): Owner
    {
        $shares = $lease->unit->property->shares;
        if ($shares->count() !== 1) {
            throw new FinanceException('Invoice issuer for multi-owner properties awaits the tax opinion (REQ-0561) and multi-owner support (phase 2).');
        }
        return $shares->first()->owner;
    }

    /** Allocations of payments that are still recorded (a reversed payment's rows stay, append-only). */
    public function hasLiveAllocations(Invoice $invoice): bool
    {
        return $invoice->allocations()->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))->exists();
    }
}
