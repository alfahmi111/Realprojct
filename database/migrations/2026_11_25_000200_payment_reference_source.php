<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M22, owner decision Q38 (2026-11-25):
 *  - an external transaction is unique per office and per SOURCE that issued its reference (Ejar, SADAD, the bank, the
 *    card gateway) — no longer per receiving account, so changing «paid to» or the tenant does not let it in again;
 *  - an electronic payment without a complete reference waits as «pending_match» with no receipt number, no
 *    allocation and no ledger entry until it is matched — receipt_number becomes nullable for that state.
 * Existing keyed rows get ref_source «unspecified» (their source was never asked); nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('ref_source', 20)->nullable();
        });
        DB::statement('ALTER TABLE payments ALTER COLUMN receipt_number DROP NOT NULL');
        app(\App\Tenancy\TenantContext::class)->run(null, fn () => DB::table('payments')->whereNotNull('external_key')->update(['ref_source' => 'unspecified']));
        DB::statement('DROP INDEX IF EXISTS payments_external_key_unique');
        DB::statement("CREATE UNIQUE INDEX payments_external_ref_unique ON payments (subscriber_id, ref_source, external_key) WHERE external_key IS NOT NULL AND status = 'recorded'");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status CHECK (status IN ('recorded','reversed','pending_match'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_receipt_when_recorded CHECK (status = 'pending_match' OR receipt_number IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_receipt_when_recorded');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status');
        DB::statement('DROP INDEX IF EXISTS payments_external_ref_unique');
        DB::statement("CREATE UNIQUE INDEX payments_external_key_unique ON payments (subscriber_id, paid_to, external_key) WHERE external_key IS NOT NULL AND status = 'recorded'");
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('ref_source');
        });
    }
};
