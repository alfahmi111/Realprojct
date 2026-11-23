<?php

namespace App\Finance;

use App\Integrations\Accounting\AccountingGateway;
use App\Models\JournalEntry;
use App\Models\SubscriberIntegration;
use Illuminate\Support\Facades\DB;

/**
 * Export journal entries to the office's accounting system — Qoyod / Zoho / Odoo (REQ-0473, REQ-0056, REQ-0580),
 * through the accounting layer (REQ-0489). Each entry is exported once (accounting_exports keeps the external ref,
 * the journal itself stays append-only).
 */
class AccountingExport
{
    public function __construct(private AccountingGateway $gateway)
    {
    }

    public function run(int $limit = 500): int
    {
        $account = SubscriberIntegration::where('integration', 'accounting')->where('active', true)->first()?->credentials ?? [];
        $entries = JournalEntry::whereNotIn('id', DB::table('accounting_exports')->select('journal_entry_id'))->with('lines')->orderBy('id')->limit($limit)->get();
        if ($entries->isEmpty()) {
            return 0;
        }
        $payload = $entries->map(fn ($e) => [
            'date' => $e->entry_date->toDateString(), 'memo' => $e->memo_ar, 'kind' => $e->kind,
            'lines' => $e->lines->map(fn ($l) => ['account' => $l->account, 'debit' => $l->debit_minor->toDecimalString(), 'credit' => $l->credit_minor->toDecimalString()])->all(),
        ])->all();
        $refs = $this->gateway->exportEntries($account, $payload);
        DB::transaction(function () use ($entries, $refs) {
            foreach ($entries->values() as $i => $e) {
                DB::table('accounting_exports')->insert(['subscriber_id' => $e->subscriber_id, 'journal_entry_id' => $e->id, 'system' => $this->gateway->system(), 'external_ref' => $refs[$i], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return $entries->count();
    }
}
