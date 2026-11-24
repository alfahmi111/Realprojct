<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System review M18 (agents), additive only:
 *  - a reconciliation issue may be resolved again later: the unique key now covers OPEN issues only (before, resolving
 *    the same subject a second time hit a duplicate "resolved" row and the item could never leave the queue);
 *  - indexes for the hot paths the review measured as sequential scans: subscriber_id on the busiest RLS tables,
 *    invoice/payment links, journal sources, and the outbox subject used by the once-per-stage checks.
 */
return new class extends Migration
{
    private const INDEXES = [
        'leases' => ['subscriber_id'], 'tenants' => ['subscriber_id'], 'invoices' => ['subscriber_id', 'lease_id', 'tenant_id'],
        'payment_allocations' => ['invoice_id', 'payment_id'], 'journal_entries' => ['subscriber_id'], 'journal_lines' => ['journal_entry_id'],
        'invoice_lines' => ['invoice_id'], 'billing_schedule_lines' => ['lease_id'], 'notifications_outbox' => ['subscriber_id'],
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE reconciliation_issues DROP CONSTRAINT IF EXISTS reconciliation_issues_subscriber_id_kind_subject_type_subject_id_status_unique');
        DB::statement('DROP INDEX IF EXISTS reconciliation_issues_subscriber_id_kind_subject_type_subject_id_status_unique');
        // PostgreSQL truncates identifiers to 63 characters: the real name of the old key
        DB::statement('ALTER TABLE reconciliation_issues DROP CONSTRAINT IF EXISTS reconciliation_issues_subscriber_id_kind_subject_type_subject_i');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS reconciliation_issues_open_unique ON reconciliation_issues (subscriber_id, kind, subject_type, subject_id) WHERE status = 'open'");
        foreach (self::INDEXES as $table => $cols) {
            foreach ($cols as $c) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$c}_idx ON {$table} ({$c})");
            }
        }
        DB::statement('CREATE INDEX IF NOT EXISTS journal_entries_source_idx ON journal_entries (source_type, source_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_outbox_subject_idx ON notifications_outbox (subject_type, subject_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_outbox_subject_idx');
        DB::statement('DROP INDEX IF EXISTS journal_entries_source_idx');
        foreach (self::INDEXES as $table => $cols) {
            foreach ($cols as $c) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$c}_idx");
            }
        }
        DB::statement('DROP INDEX IF EXISTS reconciliation_issues_open_unique');
        DB::statement('ALTER TABLE reconciliation_issues ADD CONSTRAINT reconciliation_issues_subscriber_id_kind_subject_type_subject_id_status_unique UNIQUE (subscriber_id, kind, subject_type, subject_id, status)');
    }
};
