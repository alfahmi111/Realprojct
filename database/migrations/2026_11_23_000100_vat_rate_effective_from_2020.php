<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Q18 settled by the owner (2026-11-23): the standard VAT rate of 15% applies from 2020-07-01 (REQ-0414, REQ-0420),
 * so invoices for imported existing leases with earlier dates find their rate. Platform-level policy row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.platform', 'on', true)");
            DB::table('policies')->where('code', 'KSA-VAT-STANDARD')->where('effective_from', '2026-11-22')
                ->update(['effective_from' => '2020-07-01', 'needs_official_check' => false, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.platform', 'on', true)");
            DB::table('policies')->where('code', 'KSA-VAT-STANDARD')->where('effective_from', '2020-07-01')
                ->update(['effective_from' => '2026-11-22', 'needs_official_check' => true, 'updated_at' => now()]);
        });
    }
};
