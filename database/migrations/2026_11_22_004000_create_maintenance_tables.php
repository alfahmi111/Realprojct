<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets & maintenance (spec §6): vendors, warranties / annual maintenance contracts, PPM plans, work orders with
 * the lifecycle of §6.3, cost lines, founding inventory counts (§6.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('commercial_registration', 30)->nullable();   // REQ-0235
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->unsignedTinyInteger('rating')->nullable();           // 1–5
            $t->jsonb('agreed_prices')->default('[]');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('warranties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('kind', 30);                     // manufacturer | supplier | annual_contract (REQ-0220)
            $t->foreignId('asset_id')->nullable()->constrained('assets');
            $t->foreignId('building_id')->nullable()->constrained('buildings');   // annual contracts per building (lifts, central AC, pest control)
            $t->foreignId('vendor_id')->constrained('vendors');
            $t->string('scope_ar');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('reference')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement('ALTER TABLE warranties ADD CONSTRAINT warranties_target CHECK (asset_id IS NOT NULL OR building_id IS NOT NULL)');

        Schema::create('ppm_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('asset_id')->nullable()->constrained('assets');
            $t->foreignId('space_id')->nullable()->constrained('spaces');
            $t->string('title_ar');
            $t->unsignedSmallInteger('every_months');      // e.g. filter cleaning every 3 months (REQ-0223)
            $t->date('next_due_on');
            $t->foreignId('vendor_id')->nullable()->constrained('vendors');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
        });

        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('number', 40);
            $t->string('type', 20);                        // corrective | preventive | statutory | unit_prep | emergency (REQ-0221…REQ-0226)
            $t->string('priority', 20)->nullable();        // emergency | urgent | normal | scheduled (REQ-0231)
            $t->foreignId('property_id')->constrained('properties');
            $t->foreignId('space_id')->nullable()->constrained('spaces');
            $t->foreignId('asset_id')->nullable()->constrained('assets');
            $t->foreignId('lease_id')->nullable()->constrained('leases');
            $t->foreignId('ppm_plan_id')->nullable()->constrained('ppm_plans');
            $t->string('reported_by_type', 20);            // tenant | supervisor | system
            $t->foreignId('reported_by_user_id')->nullable()->constrained('users');
            $t->text('description');
            $t->jsonb('photos_report')->default('[]');
            $t->bigInteger('estimated_cost_minor')->nullable();
            $t->boolean('owner_approval_required')->default(false);
            $t->string('owner_approval', 20)->nullable();   // pending | approved | rejected
            $t->foreignId('warranty_id')->nullable()->constrained('warranties');   // routed to the warranty vendor (REQ-0220)
            $t->string('assignee_type', 20)->nullable();   // internal | vendor
            $t->foreignId('assignee_user_id')->nullable()->constrained('users');
            $t->foreignId('vendor_id')->nullable()->constrained('vendors');
            $t->jsonb('photos_before')->default('[]');
            $t->jsonb('photos_after')->default('[]');
            $t->unsignedTinyInteger('tenant_rating')->nullable();
            $t->text('tenant_feedback')->nullable();
            $t->boolean('tenant_caused')->default(false);   // misuse → recharge with documented approval (REQ-0232)
            $t->string('tenant_caused_approval')->nullable();
            $t->string('status', 30)->default('reported');
            $t->timestampTz('reported_at');
            $t->timestampTz('responded_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
            $t->unique(['subscriber_id', 'number']);
        });
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT wo_status CHECK (status IN ('reported','triaged','estimated','awaiting_owner_approval','assigned','in_progress','completed','confirmed','closed','rejected'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT wo_type CHECK (type IN ('corrective','preventive','statutory','unit_prep','emergency'))");

        Schema::create('cost_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('work_order_id')->constrained('work_orders');
            $t->string('kind', 20);                 // labour | part | vendor (REQ-0146)
            $t->string('description_ar');
            $t->unsignedInteger('quantity')->default(1);
            $t->bigInteger('unit_cost_minor');
            $t->bigInteger('total_minor');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE cost_lines ADD CONSTRAINT cost_lines_total CHECK (total_minor = unit_cost_minor * quantity AND unit_cost_minor >= 0)');

        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('type', 20);                 // founding (MVP) | entry | periodic | exit | surprise | common_area (P2)
            $t->foreignId('space_id')->constrained('spaces');
            $t->date('counted_on');
            $t->foreignId('performed_by')->constrained('users');
            $t->string('technician_signature')->nullable();
            $t->string('tenant_signature')->nullable();
            $t->string('status', 20)->default('open');   // open | submitted
            $t->timestamps();
        });
        Schema::create('inventory_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('inventory_count_id')->constrained('inventory_counts');
            $t->foreignId('asset_id')->constrained('assets');
            $t->boolean('present');
            $t->unsignedTinyInteger('condition')->nullable();   // 1–5
            $t->string('photo');                                 // mandatory (REQ-0252)
            $t->text('note')->nullable();
            $t->boolean('added_during_count')->default(false);   // found but not registered (REQ-0253)
            $t->timestamps();
            $t->unique(['inventory_count_id', 'asset_id']);
        });
        DB::statement('ALTER TABLE inventory_lines ADD CONSTRAINT inventory_lines_condition CHECK ((present AND condition BETWEEN 1 AND 5) OR (NOT present AND condition IS NULL))');
        DB::statement("ALTER TABLE inventory_lines ADD CONSTRAINT inventory_lines_photo CHECK (length(photo) > 0)");

        foreach (['vendors', 'warranties', 'ppm_plans', 'work_orders', 'cost_lines', 'inventory_counts', 'inventory_lines'] as $table) {
            Rls::enable($table);
        }
        foreach (['vendors', 'warranties', 'work_orders'] as $table) {
            Rls::archiveOnly($table);
        }
        Rls::appendOnly('cost_lines');
    }

    public function down(): void
    {
        foreach (['inventory_lines', 'inventory_counts', 'cost_lines', 'work_orders', 'ppm_plans', 'warranties', 'vendors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
