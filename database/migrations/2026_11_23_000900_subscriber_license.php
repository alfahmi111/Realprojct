<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The office's own licence (owner approval Q31, 2026-11-23; spec 9.6 puts licences on the subscriber): kind, number
 * and expiry, alerted 30 and 7 days before as in 6.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $t) {
            $t->string('license_kind', 30)->nullable();      // property_management | brokerage | both
            $t->string('license_no', 60)->nullable();
            $t->date('license_expires_on')->nullable();
        });
        DB::statement("ALTER TABLE subscribers ADD CONSTRAINT subscribers_license_kind CHECK (license_kind IS NULL OR license_kind IN ('property_management','brokerage','both'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscribers DROP CONSTRAINT IF EXISTS subscribers_license_kind');
        Schema::table('subscribers', fn (Blueprint $t) => $t->dropColumn(['license_kind', 'license_no', 'license_expires_on']));
    }
};
