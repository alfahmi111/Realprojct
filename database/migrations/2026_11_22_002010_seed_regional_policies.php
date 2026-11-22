<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Initial rows of the policies table, exactly as written in docs/SPEC.md §5.6–§5.7. Each row is flagged
 * needs_official_check: it must be matched with the official text before launch (REQ-0195, rule R-19).
 * Ejar fees (REQ-0204) are NOT seeded: the spec gives no amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        // Riyadh: no increase for 5 years from 25 Sep 2025 (to Sep 2030); vacant units fixed at the last registered
        // contract; applies to existing AND new contracts (REQ-0197, REQ-0201, REQ-0678).
        DB::table('policies')->insert([
            'kind' => 'rent_freeze', 'region' => 'riyadh', 'city' => 'riyadh',
            'effective_from' => '2025-09-25', 'effective_to' => '2030-09-24',
            'code' => 'RIYADH-RENT-FREEZE-2025', 'title_ar' => 'إيقاف الزيادة في إيجارات الرياض داخل النطاق العمراني',
            'title_en' => 'Riyadh rent increase freeze within the urban boundary',
            'payload' => json_encode(['usages' => ['residential', 'commercial'], 'scope' => 'within_urban_boundary', 'vacant_rent' => 'last_registered', 'applies_to' => ['existing', 'new']]),
            'source_url' => 'https://www.uqn.gov.sa/details?p=27494', 'needs_official_check' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        // Riyadh initial non-renewal reason codes (REQ-0195). "Other cases set by the authority" are added as rows later.
        foreach ([
            ['RIYADH-NR-NONPAYMENT', 'عدم سداد المستأجر', 'Tenant non-payment'],
            ['RIYADH-NR-STRUCTURAL', 'عيوب إنشائية مثبتة بتقرير فني', 'Structural defects proven by a technical report'],
            ['RIYADH-NR-OWNER-USE', 'سكن المالك أو قريب من الدرجة الأولى', 'Owner or first-degree relative to reside'],
        ] as [$code, $ar, $en]) {
            DB::table('policies')->insert([
                'kind' => 'non_renewal_reason', 'region' => 'riyadh', 'city' => 'riyadh', 'effective_from' => '2025-09-25', 'effective_to' => null,
                'code' => $code, 'title_ar' => $ar, 'title_en' => $en, 'payload' => json_encode(['requires_document' => true]),
                'source_url' => 'https://www.uqn.gov.sa/details?p=27494', 'needs_official_check' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Policies are archive-only; leave rows in place.
    }
};
