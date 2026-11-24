<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M22, owner decision Q37 option (أ) (2026-11-25): a work order's payment is tracked apart from its work stage.
 * The cost is paid either out of the owner's trust balance (approval request, as before) or by the owner straight to
 * the vendor, recorded with proof of payment — that path never touches trust. Office financing (option ب) is deferred.
 *
 * payment_status: not_required | awaiting_trust_approval | paid_from_trust | trust_rejected | awaiting_owner_payment | paid_by_owner
 * Existing closed orders are filled from their approval request (nothing else changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $t) {
            $t->string('payment_status', 30)->nullable();
            $t->string('paid_by', 10)->nullable();                 // trust | owner
            $t->string('owner_payment_proof')->nullable();
            $t->date('owner_paid_on')->nullable();
            $t->string('owner_payment_reference')->nullable();
            $t->bigInteger('owner_payment_amount_minor')->nullable();
            $t->foreignId('owner_payment_recorded_by')->nullable()->constrained('users');
        });
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_payment_status CHECK (payment_status IS NULL OR payment_status IN ('not_required','awaiting_trust_approval','paid_from_trust','trust_rejected','awaiting_owner_payment','paid_by_owner'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_owner_paid_proof CHECK (payment_status <> 'paid_by_owner' OR (owner_payment_proof IS NOT NULL AND owner_paid_on IS NOT NULL))");

        app(\App\Tenancy\TenantContext::class)->run(null, function () {
            foreach (DB::table('work_orders')->where('status', 'closed')->whereNull('payment_status')->get(['id', 'subscriber_id', 'number']) as $wo) {
                $req = DB::table('approval_requests')->where('subscriber_id', $wo->subscriber_id)->where('action', 'trust.disburse')
                    ->whereRaw("payload->>'work_order' = ?", [$wo->number])->orderByDesc('id')->first(['status']);
                $status = match ($req?->status) {
                    'approved' => 'paid_from_trust', 'pending' => 'awaiting_trust_approval', 'rejected' => 'trust_rejected', default => 'not_required',
                };
                DB::table('work_orders')->where('id', $wo->id)->update(['payment_status' => $status, 'paid_by' => $req ? 'trust' : null]);
            }
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_owner_paid_proof');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_payment_status');
        Schema::table('work_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_payment_recorded_by');
            $t->dropColumn(['payment_status', 'paid_by', 'owner_payment_proof', 'owner_paid_on', 'owner_payment_reference', 'owner_payment_amount_minor']);
        });
    }
};
