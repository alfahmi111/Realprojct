<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision Q33 (2026-11-24): rent and deposits collected for an owner go into the owner's own account, following
 * the draft property-management regulation of the Real Estate General Authority. A regulatory rule, so it is a row in
 * the policies table, nationwide. payload.mode:
 *   default  — the owner's account is the default everywhere; collecting into the office trust account stays possible
 *              with a visible warning (the draft is not yet in force);
 *   required — collecting into the office account is refused (switch to this when the regulation is approved).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('policies')->insert([
            'kind' => 'funds_to_owner_account', 'region' => null, 'city' => null, 'effective_from' => '2026-11-24', 'effective_to' => null,
            'code' => 'REGA-DRAFT-OWNER-ACCOUNT', 'title_ar' => 'إيداع المبالغ المحصّلة في حساب المالك (مسودة لائحة إدارة الأملاك)',
            'title_en' => 'Collected funds deposited in the owner account (draft property management regulation)',
            'payload' => json_encode(['mode' => 'default', 'applies_to' => ['rent', 'deposit']]),
            'source_url' => null, 'needs_official_check' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('policies')->where('code', 'REGA-DRAFT-OWNER-ACCOUNT')->delete();
    }
};
