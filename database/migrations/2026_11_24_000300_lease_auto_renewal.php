<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision Q35 (2026-11-24): a lease that ends with no notice renews automatically for the same term, and the
 * renewed lease can be ended. `auto_renewed_at` marks a renewal the platform created; `terminated_*` record an
 * early end of such a renewal (who asked, why, when it was recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $t) {
            $t->timestamp('auto_renewed_at')->nullable();
            $t->string('terminated_by', 20)->nullable();      // lessor | tenant
            $t->string('termination_reason', 500)->nullable();
            $t->timestamp('terminated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', fn (Blueprint $t) => $t->dropColumn(['auto_renewed_at', 'terminated_by', 'termination_reason', 'terminated_at']));
    }
};
