<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscribers (SaaS tenants), user roles, RLS on users (REQ-0169, REQ-0487, REQ-0494, REQ-0057…REQ-0065).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform-level table: managed by the platform admin, no tenant business data (REQ-0065).
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('plan', 20);            // owner | office | company (spec §13, §7.6أ)
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE subscribers ADD CONSTRAINT subscribers_plan_check CHECK (plan IN ('owner','office','company'))");

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('subscriber_id')->nullable()->after('id')->constrained('subscribers');
            $table->string('name_en')->nullable()->after('name');
            $table->string('role', 40)->after('email');
            $table->string('phone', 30)->nullable()->after('email');
            $table->timestamp('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('platform_admin','owner','institutional_owner','manager','accountant','leasing_officer','maintenance_supervisor','technician','tenant'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_platform_admin_has_no_subscriber CHECK ((role = 'platform_admin') = (subscriber_id IS NULL))");

        // Users: rows of a subscriber are visible only in its context; a single row is reachable during
        // authentication by its id or its login e-mail (set by TenantContext before the lookup).
        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON users USING (
            subscriber_id = ' . Rls::CURRENT . "
            OR id = nullif(current_setting('app.user_id', true), '')::bigint
            OR lower(email) = lower(nullif(current_setting('app.login_email', true), ''))
            OR (subscriber_id IS NULL AND " . Rls::CURRENT . " IS NULL AND current_setting('app.platform', true) = 'on')
        ) WITH CHECK (
            subscriber_id IS NOT DISTINCT FROM " . Rls::CURRENT . "
            OR id = nullif(current_setting('app.user_id', true), '')::bigint
        )");

        // Role + scope (which properties a user sees) — REQ-0494. Property FK added with the property model (M2).
        Schema::create('user_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('user_id')->constrained('users');
            $table->string('scope_type', 30);   // portfolio | property | building | unit
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
        });
        Rls::enable('user_scopes');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_scopes');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON users');
        DB::statement('ALTER TABLE users DISABLE ROW LEVEL SECURITY');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscriber_id');
            $table->dropColumn(['name_en', 'role', 'phone', 'archived_at']);
        });
        Schema::dropIfExists('subscribers');
    }
};
