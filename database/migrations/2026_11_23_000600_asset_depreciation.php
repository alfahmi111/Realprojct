<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Straight-line depreciation of in-unit assets (owner decision 2026-11-23, builds on cost + useful life of REQ-0211 and
 * the replacement budget of REQ-0262). Each asset states its yearly depreciation either as a share of its cost
 * (basis points per year) or as a fixed yearly amount; when neither is set, the useful life spreads the cost evenly.
 * Salvage value is optional and counts as zero when empty (owner decision 2026-11-23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->string('depreciation_method', 10)->nullable();          // percent | amount | null = by useful life
            $t->unsignedInteger('depreciation_rate_bp')->nullable();    // yearly share of cost, 1000 = 10 %
            $t->bigInteger('depreciation_annual_minor')->nullable();    // fixed yearly amount in halalas
            $t->bigInteger('salvage_minor')->nullable();                // value left at end of service
        });
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_dep_method CHECK (depreciation_method IS NULL OR depreciation_method IN ('percent','amount'))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_dep_values CHECK ((depreciation_rate_bp IS NULL OR depreciation_rate_bp BETWEEN 1 AND 10000) AND (depreciation_annual_minor IS NULL OR depreciation_annual_minor > 0) AND (salvage_minor IS NULL OR salvage_minor >= 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_dep_method');
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_dep_values');
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn(['depreciation_method', 'depreciation_rate_bp', 'depreciation_annual_minor', 'salvage_minor']);
        });
    }
};
