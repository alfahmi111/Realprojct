<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements to tenants (REQ-0278), extended at the owner's request 2026-11-23: an end date, several buildings at
 * once, and units excluded from the audience. building_ids empty = every building. The old single building_id is
 * copied into building_ids and kept for history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->jsonb('building_ids')->default('[]');
            $t->jsonb('excluded_unit_ids')->default('[]');
            $t->timestamp('expires_at')->nullable();
        });
        DB::statement("UPDATE announcements SET building_ids = jsonb_build_array(building_id) WHERE building_id IS NOT NULL");
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_window CHECK (expires_at IS NULL OR expires_at > published_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE announcements DROP CONSTRAINT IF EXISTS announcements_window');
        Schema::table('announcements', function (Blueprint $t) {
            $t->dropColumn(['building_ids', 'excluded_unit_ids', 'expires_at']);
        });
    }
};
