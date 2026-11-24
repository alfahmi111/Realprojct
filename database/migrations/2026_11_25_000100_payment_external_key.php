<?php

use App\Finance\PaymentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M22 (audit 2026-11-24, REQ-0316): one external payment transaction is recorded once.
 * external_key = the transaction's reference normalised (PaymentService::externalKey). Uniqueness covers the receiving
 * account (paid_to) and recorded payments only, so a reversed payment can be entered again correctly; the index is the
 * guard against two requests that pass the service check at the same moment.
 *
 * Existing rows: the first recorded payment of each key gets it; any later one with the same key keeps NULL (nothing
 * is changed or reversed here) and the nightly reconciliation lists it as «duplicate_payment_reference» for a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('external_key', 200)->nullable();
        });

        $seen = [];
        app(\App\Tenancy\TenantContext::class)->run(null, function () use (&$seen) {
            DB::table('payments')->where('status', 'recorded')->whereNotNull('reference')->orderBy('id')->select(['id', 'subscriber_id', 'paid_to', 'reference'])
                ->chunkById(500, function ($rows) use (&$seen) {
                    foreach ($rows as $r) {
                        $key = PaymentService::externalKey($r->reference);
                        $scope = $r->subscriber_id . '|' . $r->paid_to . '|' . $key;
                        if ($key === null || isset($seen[$scope])) {
                            continue;
                        }
                        $seen[$scope] = true;
                        DB::table('payments')->where('id', $r->id)->update(['external_key' => $key]);
                    }
                });
        });

        DB::statement("CREATE UNIQUE INDEX payments_external_key_unique ON payments (subscriber_id, paid_to, external_key) WHERE external_key IS NOT NULL AND status = 'recorded'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_external_key_unique');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('external_key');
        });
    }
};
