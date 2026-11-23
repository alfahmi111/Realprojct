<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Building QR — free version (spec §8, REQ-0585): live page with current and confirmed vacancies, free prospect
 * registration (decision 3: no fees), permanent short link, scan tracking, pause, ad-licence requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The short code is resolved publicly before any subscriber context exists → a platform-level lookup table
        // that holds only the code → (subscriber, building) mapping, no personal data.
        Schema::create('qr_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique();
            $t->unsignedBigInteger('subscriber_id');
            $t->unsignedBigInteger('building_id');
            $t->timestamps();
        });
        DB::statement('CREATE TRIGGER qr_codes_immutable BEFORE UPDATE OR DELETE ON qr_codes FOR EACH ROW EXECUTE FUNCTION app_reject_change(\'append-only\')');

        Schema::create('building_qrs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('building_id')->unique()->constrained('buildings');   // BUILDING ||--|| BUILDING_QR (REQ-0147)
            $t->string('code', 16)->unique();
            $t->boolean('paused')->default(false);                 // REQ-0395
            $t->string('ad_license_no', 60)->nullable();           // REQ-0389
            $t->date('ad_license_expires_on')->nullable();
            $t->unsignedBigInteger('scan_count')->default(0);
            $t->timestamps();
        });

        Schema::create('qr_scans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('building_qr_id')->constrained('building_qrs');
            $t->string('source', 60)->nullable();                  // ?src=board|flyer|… (REQ-0394)
            $t->string('referrer')->nullable();
            $t->timestampTz('scanned_at');
        });

        Schema::create('prospects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('building_qr_id')->constrained('building_qrs');   // BUILDING_QR ||--o{ PROSPECT (REQ-0148)
            $t->text('name');                                       // encrypted personal data (REQ-0391)
            $t->text('phone');
            $t->text('email')->nullable();
            $t->string('unit_type', 30)->nullable();
            $t->bigInteger('budget_minor')->nullable();
            $t->string('desired_month', 7)->nullable();             // YYYY-MM
            $t->timestampTz('consent_at');                          // explicit consent, specific purpose (REQ-0391)
            $t->string('consent_version', 20);
            $t->timestampTz('last_confirmed_at');
            $t->string('status', 20)->default('active');            // active | removed
            $t->string('removal_reason', 40)->nullable();
            $t->timestamps();
        });

        Schema::create('expected_vacancies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('leasable_unit_id')->constrained('leasable_units');
            $t->foreignId('lease_id')->nullable()->constrained('leases');
            $t->string('month', 7);
            $t->string('confidence', 20);                           // confirmed (MVP) | probable | possible (P2)
            $t->string('source', 20);                               // notice | survey | indicator
            $t->timestamps();
            $t->unique(['leasable_unit_id', 'lease_id']);
        });

        foreach (['building_qrs', 'qr_scans', 'prospects', 'expected_vacancies'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['expected_vacancies', 'prospects', 'qr_scans', 'building_qrs', 'qr_codes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
