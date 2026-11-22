<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets attached to any node of the tree (REQ-0073, REQ-0144, REQ-0211) — base register, extended in M5.
 * Rate plans: a price per leasable unit per duration unit (REQ-0128, REQ-0138, REQ-0177).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('space_id')->constrained('spaces');
            $t->string('qr_code', 40)->unique();                 // internal QR code
            $t->string('category', 40);                          // taxonomy (REQ-0212…REQ-0219)
            $t->string('type', 60);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_number')->nullable();
            $t->date('installed_on')->nullable();
            $t->date('warranty_ends_on')->nullable();
            $t->unsignedTinyInteger('condition')->nullable();    // 1–5
            $t->bigInteger('cost_minor')->nullable();
            $t->unsignedSmallInteger('useful_life_months')->nullable();
            $t->jsonb('photos')->default('[]');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_condition CHECK (condition IS NULL OR condition BETWEEN 1 AND 5)');

        Schema::create('rate_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('leasable_unit_id')->constrained('leasable_units');
            $t->string('duration_unit', 10);     // day | week | month | year
            $t->bigInteger('price_minor');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_unit CHECK (duration_unit IN ('day','week','month','year'))");
        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_price CHECK (price_minor > 0)');
        DB::statement('CREATE UNIQUE INDEX rate_plans_active_unique ON rate_plans (leasable_unit_id, duration_unit) WHERE archived_at IS NULL');

        foreach (['assets', 'rate_plans'] as $table) {
            Rls::enable($table);
            Rls::archiveOnly($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('assets');
    }
};
