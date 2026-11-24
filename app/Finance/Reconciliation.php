<?php

namespace App\Finance;

use App\Integrations\Ejar\EjarGateway;
use App\Models\BillingScheduleLine;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\ReconciliationIssue;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Nightly reconciliation between three naturally unconnected sources — the contract (Ejar), the invoice (ZATCA)
 * and the payment (bank / Sadad / mada) — REQ-0434. Every difference becomes a human work-queue item instead of
 * stopping the system. Before automatic Ejar access, the subscriber's Ejar CSV export is matched (REQ-0435, REQ-0461).
 */
class Reconciliation
{
    public function __construct(private InvoiceService $invoices, private EjarGateway $ejar)
    {
    }

    /** @return array<string, int> number of issues found per kind */
    public function run(CarbonImmutable $today): array
    {
        $found = [];
        // 1. Registered/active lease with a due schedule line but no invoice.
        $due = BillingScheduleLine::where('status', 'scheduled')->whereNull('archived_at')->where('due_on', '<=', $today->toDateString())
            ->whereHas('lease', fn ($q) => $q->whereIn('status', ['registered', 'active', 'notice_given']))->get();
        foreach ($due as $l) {
            $this->flag('registered_lease_without_invoice', 'billing_schedule_line', $l->id, ['lease_id' => $l->lease_id, 'due_on' => $l->due_on->toDateString(), 'amount' => $l->amount_minor->toDecimalString()], $today, $found);
        }
        foreach (Invoice::where('status', 'issued')->where('kind', 'rent')->with('lines')->get() as $inv) {
            $lease = $inv->lease_id ? Lease::find($inv->lease_id) : null;
            // 2. Invoice without a contract (or without its Ejar number).
            if (! $lease || ! $lease->ejar_contract_number) {
                $this->flag('invoice_without_lease', 'invoice', $inv->id, ['number' => $inv->number], $today, $found);
                continue;
            }
            // 3. Invoice on an expired/closed contract (issued after the end).
            if (in_array($lease->status, ['expired', 'settled_closed', 'cancelled'], true) && $inv->period_start && $inv->period_start->greaterThan($lease->ends_on)) {
                $this->flag('invoice_on_expired_lease', 'invoice', $inv->id, ['number' => $inv->number, 'lease_id' => $lease->id], $today, $found);
            }
            // 4. Amount not matching its schedule line.
            if ($inv->billing_schedule_line_id) {
                $line = BillingScheduleLine::find($inv->billing_schedule_line_id);
                if ($line && ! $line->amount_minor->equals($inv->subtotal_minor)) {
                    $this->flag('amount_mismatch', 'invoice', $inv->id, ['invoice' => $inv->subtotal_minor->toDecimalString(), 'schedule' => $line->amount_minor->toDecimalString()], $today, $found);
                }
            }
        }
        // 5. Payment not (fully) allocated.
        foreach (Payment::where('status', 'recorded')->with('allocations')->get() as $p) {
            $allocated = Money::sum($p->allocations->map(fn ($a) => $a->amount_minor)->all());
            if ($allocated->lessThan($p->amount_minor)) {
                $this->flag('unallocated_payment', 'payment', $p->id, ['receipt' => $p->receipt_number, 'unallocated' => $p->amount_minor->minus($allocated)->toDecimalString()], $today, $found);
            }
        }
        // 6. The same outside transaction recorded more than once (M22): rows from before the unique key existed keep
        //    no key, so a person reviews them and reverses the extra one through maker/checker; nothing is changed here.
        $byKey = [];
        foreach (Payment::where('status', 'recorded')->whereNotNull('reference')->orderBy('id')->get(['id', 'paid_to', 'reference', 'receipt_number']) as $p) {
            if (($k = PaymentService::externalKey($p->reference)) !== null) {
                $byKey[$p->paid_to . '|' . $k][] = $p;
            }
        }
        foreach ($byKey as $same) {
            foreach (array_slice($same, 1) as $dup) {
                $this->flag('duplicate_payment_reference', 'payment', $dup->id, ['receipt' => $dup->receipt_number, 'first_receipt' => $same[0]->receipt_number, 'reference' => $dup->reference], $today, $found);
            }
        }
        return $found;
    }

    /**
     * Match the Ejar CSV export (columns: contract number, status) against our leases (REQ-0435).
     * Status words are compared through a configurable map; the default map covers the five Ejar statuses.
     */
    public function matchEjarCsv(string $csvPath, CarbonImmutable $today, array $columns = ['number' => 'رقم العقد', 'status' => 'الحالة'], ?array $statusMap = null): array
    {
        $statusMap ??= ['مسودة' => 'draft', 'مرسل' => 'sent', 'بانتظار الأطراف' => 'awaiting_parties', 'موثق' => 'registered', 'موثّق' => 'registered', 'منتهي' => 'expired', 'منتهٍ' => 'expired'];
        $ours = ['draft' => ['draft'], 'sent' => ['sent_for_registration'], 'awaiting_parties' => ['awaiting_parties'], 'registered' => ['registered', 'active', 'notice_given'], 'expired' => ['expired', 'renewed', 'settled_closed']];
        $found = [];
        $seen = [];
        foreach ($this->ejar->importContracts($csvPath) as $row) {
            $number = trim($row[$columns['number']] ?? '');
            if ($number === '') {
                continue;
            }
            $seen[] = $number;
            $lease = Lease::where('ejar_contract_number', $number)->first();
            if (! $lease) {
                $this->flag('ejar_contract_unknown', 'ejar_contract', $number, $row, $today, $found);
                continue;
            }
            $ejarStatus = $statusMap[trim($row[$columns['status']] ?? '')] ?? null;
            if ($ejarStatus && ! in_array($lease->status, $ours[$ejarStatus], true)) {
                $this->flag('ejar_status_mismatch', 'lease', $lease->id, ['ejar' => $ejarStatus, 'ours' => $lease->status, 'number' => $number], $today, $found);
            }
        }
        foreach (Lease::whereNotNull('ejar_contract_number')->whereIn('status', ['registered', 'active'])->whereNotIn('ejar_contract_number', $seen)->get() as $l) {
            $this->flag('lease_missing_in_ejar_export', 'lease', $l->id, ['number' => $l->ejar_contract_number], $today, $found);
        }
        return $found;
    }

    public function resolve(ReconciliationIssue $issue, int $userId, string $note): void
    {
        $issue->forceFill(['status' => 'resolved', 'resolved_by' => $userId, 'resolution_note' => $note])->save();
    }

    private function flag(string $kind, string $type, int|string $id, array $details, CarbonImmutable $today, array &$found): void
    {
        $issue = ReconciliationIssue::firstOrCreate(
            ['kind' => $kind, 'subject_type' => $type, 'subject_id' => (string) $id, 'status' => 'open'],
            ['details' => $details, 'detected_on' => $today->toDateString()],
        );
        if ($issue->wasRecentlyCreated) {
            $found[$kind] = ($found[$kind] ?? 0) + 1;
        }
    }
}
