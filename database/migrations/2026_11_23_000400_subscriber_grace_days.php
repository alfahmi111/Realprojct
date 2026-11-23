<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q24 (owner's decision 2026-11-23): after the subscription ends the office has a grace period (platform default 7 days,
 * daily notice), then becomes read-only automatically — export stays open, nothing is deleted. The platform admin can
 * extend the grace period for one subscriber: grace_days overrides the platform default for that subscriber only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', fn (Blueprint $t) => $t->unsignedSmallInteger('grace_days')->nullable());
    }

    public function down(): void
    {
        Schema::table('subscribers', fn (Blueprint $t) => $t->dropColumn('grace_days'));
    }
};
