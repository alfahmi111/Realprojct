<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Property model (spec §4.1–§4.6, §3.1):
 *  Portfolio → Property → Building → Space tree (floor / unit / room / common area / rentable location)
 *  Leasable unit = one or many spaces (REQ-0070…REQ-0073). Owners with shares; management agreements.
 * Every table: subscriber RLS, Arabic + English names (REQ-0167), archive instead of delete for contractual rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = function (Blueprint $t) {
            $t->string('name_ar');
            $t->string('name_en')->nullable();
        };

        Schema::create('owners', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('kind', 20);                         // individual | heirs | company (REQ-0058)
            $names($t);
            $t->text('national_id')->nullable();            // encrypted; restricted access (REQ-0391, REQ-0453)
            $t->text('commercial_registration')->nullable(); // encrypted
            $t->boolean('vat_registered')->default(false);  // needed for VAT (REQ-0414)
            $t->string('vat_number', 20)->nullable();
            $t->boolean('resident')->default(true);         // withholding tax note (REQ-0419)
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE owners ADD CONSTRAINT owners_kind_check CHECK (kind IN ('individual','heirs','company'))");

        Schema::create('portfolios', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $names($t);
            $t->foreignId('owner_id')->nullable()->constrained('owners');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('properties', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('portfolio_id')->constrained('portfolios');
            $names($t);
            $t->string('template', 30)->nullable();               // wizard template (REQ-0124)
            $t->string('deed_number', 60)->nullable();            // صك (REQ-0075, REQ-0123)
            $t->string('ejar_property_number', 60)->nullable();   // رقم العقار في إيجار
            $t->string('real_estate_registry_number', 60)->nullable(); // رقم التسجيل العيني
            $t->jsonb('national_address')->nullable();            // العنوان الوطني (REQ-0448)
            $t->string('region', 60)->nullable();                 // for regional policies (REQ-0196)
            $t->string('city', 60)->nullable();
            $t->boolean('within_urban_boundary')->nullable();     // Riyadh freeze scope (REQ-0197)
            $t->decimal('latitude', 10, 7)->nullable();           // map location — not money
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('ownership_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('property_id')->constrained('properties');
            $t->foreignId('owner_id')->constrained('owners');
            $t->unsignedInteger('share_numerator');
            $t->unsignedInteger('share_denominator');
            $t->timestamps();
            $t->unique(['property_id', 'owner_id']);
        });
        DB::statement('ALTER TABLE ownership_shares ADD CONSTRAINT ownership_shares_fraction CHECK (share_numerator > 0 AND share_denominator > 0 AND share_numerator <= share_denominator)');

        Schema::create('management_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('owner_id')->constrained('owners');
            $t->boolean('is_self')->default(false);                // owner is the manager → fee zero (REQ-0067)
            $t->string('fee_type', 20);                            // percentage | fixed | mixed (REQ-0066)
            $t->unsignedInteger('fee_percentage_bp')->default(0);  // basis points of collected rent
            $t->bigInteger('fee_fixed_minor')->default(0);         // halalas
            $t->bigInteger('spend_cap_minor')->nullable();         // سقف الصرف دون موافقة المالك
            $t->string('collector', 20);                           // manager | owner — who may collect funds
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE management_agreements ADD CONSTRAINT ma_fee_type CHECK (fee_type IN ('percentage','fixed','mixed'))");
        DB::statement("ALTER TABLE management_agreements ADD CONSTRAINT ma_collector CHECK (collector IN ('manager','owner'))");
        DB::statement('ALTER TABLE management_agreements ADD CONSTRAINT ma_self_zero CHECK (NOT is_self OR (fee_percentage_bp = 0 AND fee_fixed_minor = 0))');
        DB::statement('ALTER TABLE management_agreements ADD CONSTRAINT ma_amounts CHECK (fee_fixed_minor >= 0 AND (spend_cap_minor IS NULL OR spend_cap_minor >= 0) AND fee_percentage_bp <= 10000)');

        Schema::create('management_agreement_property', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('management_agreement_id')->constrained('management_agreements');
            $t->foreignId('property_id')->constrained('properties');
            $t->unique(['management_agreement_id', 'property_id']);
        });

        Schema::create('buildings', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('property_id')->constrained('properties');
            $names($t);
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('spaces', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('property_id')->constrained('properties');
            $t->foreignId('building_id')->nullable()->constrained('buildings');   // null for land (REQ-0082)
            $t->foreignId('parent_id')->nullable()->constrained('spaces');
            $t->string('kind', 30);          // floor | unit | room | common_area | rentable_location | land
            $t->string('unit_type', 30)->nullable();
            $t->string('code', 40);
            $names($t);
            $t->jsonb('attributes')->default('{}');
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
            $t->index(['property_id', 'parent_id']);
        });
        DB::statement("ALTER TABLE spaces ADD CONSTRAINT spaces_kind CHECK (kind IN ('floor','unit','room','common_area','rentable_location','land'))");
        DB::statement("ALTER TABLE spaces ADD CONSTRAINT spaces_unit_type CHECK ((kind IN ('unit','rentable_location','land')) = (unit_type IS NOT NULL) OR (kind = 'room'))");
        DB::statement('CREATE UNIQUE INDEX spaces_code_unique ON spaces (building_id, code) WHERE archived_at IS NULL AND building_id IS NOT NULL');

        Schema::create('leasable_units', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('property_id')->constrained('properties');
            $t->string('code', 40);
            $names($t);
            $t->string('unit_type', 30);
            $t->boolean('is_composite')->default(false);
            $t->string('allowed_tracks', 10)->default('long');      // long | short | both (REQ-0176)
            $t->string('hospitality_license_no', 60)->nullable();
            $t->date('hospitality_license_expires_on')->nullable();
            $t->bigInteger('last_registered_rent_minor')->nullable(); // آخر إيجار مسجل (REQ-0199)
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE leasable_units ADD CONSTRAINT lu_tracks CHECK (allowed_tracks IN ('long','short','both'))");
        // A unit opens for short-term (daily) letting only with a hospitality licence number and expiry (REQ-0176, REQ-0441).
        DB::statement("ALTER TABLE leasable_units ADD CONSTRAINT lu_short_needs_license CHECK (allowed_tracks = 'long' OR (hospitality_license_no IS NOT NULL AND hospitality_license_expires_on IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX leasable_units_code_unique ON leasable_units (property_id, code) WHERE archived_at IS NULL');

        Schema::create('leasable_unit_space', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('leasable_unit_id')->constrained('leasable_units');
            $t->foreignId('space_id')->constrained('spaces');
            $t->unique(['leasable_unit_id', 'space_id']);
        });

        // Default asset lists per unit type (wizard step 5, REQ-0127) — per subscriber, no invented defaults.
        Schema::create('unit_type_asset_templates', function (Blueprint $t) use ($names) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('unit_type', 30);
            $names($t);
            $t->string('category', 40);
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->timestamps();
        });

        foreach (['owners', 'portfolios', 'properties', 'ownership_shares', 'management_agreements', 'management_agreement_property', 'buildings', 'spaces', 'leasable_units', 'leasable_unit_space', 'unit_type_asset_templates'] as $table) {
            Rls::enable($table);
        }
        foreach (['owners', 'properties', 'management_agreements', 'leasable_units', 'spaces', 'buildings'] as $table) {
            Rls::archiveOnly($table);
        }

        Schema::table('property_settings', fn (Blueprint $t) => $t->foreign('property_id')->references('id')->on('properties'));
    }

    public function down(): void
    {
        Schema::table('property_settings', fn (Blueprint $t) => $t->dropForeign(['property_id']));
        foreach (['unit_type_asset_templates', 'leasable_unit_space', 'leasable_units', 'spaces', 'buildings', 'management_agreement_property', 'management_agreements', 'ownership_shares', 'properties', 'portfolios', 'owners'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
