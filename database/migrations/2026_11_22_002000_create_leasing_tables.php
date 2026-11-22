<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leasing engine (spec §5): tenants, lease offers with approvals, leases (state machine), space occupancy with a
 * database-level no-overlap guarantee, billing schedule, scheduled escalations, regional policies, non-renewal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Regional policies — regulatory rules live here, not in code (REQ-0196, REQ-0200, REQ-0204). Platform-level data.
        Schema::create('policies', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40);                 // rent_freeze | non_renewal_reason | ejar_fee
            $t->string('region', 60)->nullable();   // null = nationwide
            $t->string('city', 60)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('code', 60)->nullable();
            $t->string('title_ar');
            $t->string('title_en')->nullable();
            $t->jsonb('payload')->default('{}');
            $t->string('source_url')->nullable();
            $t->boolean('needs_official_check')->default(true);   // must be matched to the official text before launch
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('kind', 20);                       // individual | company (REQ-0063)
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->text('national_id')->nullable();          // encrypted
            $t->text('commercial_registration')->nullable();
            $t->string('vat_number', 20)->nullable();     // buyer VAT number on invoices (REQ-0424)
            $t->jsonb('national_address')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('messages_locale', 2)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_kind CHECK (kind IN ('individual','company'))");

        // Offer approval rules set by the manager: by role and by discount from the listed price (REQ-0184).
        Schema::create('offer_approval_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->unsignedSmallInteger('step');
            $t->string('approver_role', 40);
            $t->unsignedInteger('min_discount_bp')->default(0);   // the step applies when the discount ≥ this
            $t->timestamps();
        });

        Schema::create('lease_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('leasable_unit_id')->constrained('leasable_units');
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('created_by')->constrained('users');
            $t->string('usage', 20);                         // residential | commercial (tax depends on usage, REQ-0420)
            $t->date('starts_on');
            $t->string('duration_unit', 10);                 // month | year (day/week: phase 3)
            $t->unsignedSmallInteger('duration_count');
            $t->bigInteger('annual_rent_minor');
            $t->bigInteger('listed_annual_rent_minor')->nullable();
            $t->string('payment_frequency', 20);             // monthly | quarterly | semiannual | annual | single
            $t->bigInteger('deposit_minor')->default(0);
            $t->jsonb('escalations')->default('[]');
            $t->string('status', 20)->default('draft');      // draft | pending_approval | approved | sent | accepted | rejected | expired
            $t->string('accept_token', 64)->nullable()->unique();
            $t->date('valid_until')->nullable();
            $t->string('loss_reason')->nullable();           // why the tenant was lost (analysis, REQ-0184)
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('lease_offer_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('lease_offer_id')->constrained('lease_offers');
            $t->unsignedSmallInteger('step');
            $t->string('approver_role', 40);
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->string('decision', 20)->nullable();          // approved | rejected
            $t->timestampTz('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('leases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('leasable_unit_id')->constrained('leasable_units');
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('lease_offer_id')->nullable()->constrained('lease_offers');
            $t->foreignId('parent_lease_id')->nullable()->constrained('leases');    // sublease (REQ-0202) / renewal source
            $t->string('kind', 20)->default('lease');        // lease | sublease | renewal
            $t->string('usage', 20);
            $t->string('track', 10)->default('long');
            $t->date('starts_on');
            $t->date('ends_on');                             // inclusive
            $t->bigInteger('annual_rent_minor');
            $t->string('payment_frequency', 20);
            $t->bigInteger('deposit_minor')->default(0);
            $t->string('status', 30)->default('draft');
            $t->string('ejar_contract_number', 60)->nullable();   // link key to Ejar (REQ-0186)
            $t->date('non_renewal_notice_on')->nullable();
            $t->string('non_renewal_by', 20)->nullable();        // lessor | tenant
            $t->string('non_renewal_reason_code', 60)->nullable();
            $t->string('non_renewal_document')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE leases ADD CONSTRAINT leases_status CHECK (status IN ('draft','sent_for_registration','awaiting_parties','registered','active','notice_given','expired','renewed','settled_closed','cancelled'))");
        DB::statement('ALTER TABLE leases ADD CONSTRAINT leases_dates CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE leases ADD CONSTRAINT leases_frequency CHECK (payment_frequency IN ('monthly','quarterly','semiannual','annual','single'))");
        DB::statement('ALTER TABLE leases ADD CONSTRAINT leases_amounts CHECK (annual_rent_minor > 0 AND deposit_minor >= 0)');

        // Occupancy: each space is held by at most one lease/hold for any day — enforced by PostgreSQL (REQ-0179).
        Schema::create('space_occupancies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('space_id')->constrained('spaces');
            $t->foreignId('lease_id')->nullable()->constrained('leases');
            $t->foreignId('lease_offer_id')->nullable()->constrained('lease_offers');
            $t->string('kind', 20);                          // lease | hold | maintenance
            $t->timestamps();
        });
        DB::statement('ALTER TABLE space_occupancies ADD COLUMN period daterange NOT NULL');
        DB::statement('ALTER TABLE space_occupancies ADD CONSTRAINT space_occupancies_no_overlap EXCLUDE USING gist (space_id public.gist_int8_ops WITH =, period WITH &&)');

        Schema::create('billing_schedule_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('lease_id')->constrained('leases');
            $t->unsignedSmallInteger('sequence');
            $t->date('due_on');
            $t->date('period_start');
            $t->date('period_end');
            $t->bigInteger('amount_minor');
            $t->string('status', 20)->default('scheduled');   // scheduled | invoiced | void
            $t->foreignId('escalation_id')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('rent_escalations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('lease_id')->constrained('leases');
            $t->string('kind', 20);                           // percentage | amount
            $t->unsignedInteger('percentage_bp')->default(0);
            $t->bigInteger('amount_minor')->default(0);       // annual increase in halalas
            $t->date('effective_on')->nullable();             // from a date…
            $t->unsignedSmallInteger('contract_year')->nullable();   // …or from a contract year
            $t->string('status', 20)->default('scheduled');   // scheduled | applied | suspended
            $t->string('suspension_reason')->nullable();      // shown to the manager (REQ-0190)
            $t->foreignId('policy_id')->nullable()->constrained('policies');
            $t->timestamps();
        });

        foreach (['tenants', 'offer_approval_rules', 'lease_offers', 'lease_offer_approvals', 'leases', 'space_occupancies', 'billing_schedule_lines', 'rent_escalations'] as $table) {
            Rls::enable($table);
        }
        foreach (['tenants', 'lease_offers', 'leases', 'billing_schedule_lines', 'rent_escalations', 'policies'] as $table) {
            Rls::archiveOnly($table);
        }
    }

    public function down(): void
    {
        foreach (['rent_escalations', 'billing_schedule_lines', 'space_occupancies', 'leases', 'lease_offer_approvals', 'lease_offers', 'offer_approval_rules', 'tenants', 'policies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
