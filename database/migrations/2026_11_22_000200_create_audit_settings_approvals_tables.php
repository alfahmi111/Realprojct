<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit log (REQ-0068, REQ-0497, REQ-0376), settings tables (REQ-0374, REQ-0321),
 * dual approval maker/checker (REQ-0069).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 60);                  // created | updated | archived | setting.changed | approval.* ...
            $table->string('auditable_type', 120);
            $table->string('auditable_id', 64)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });
        Rls::enableWithPlatformRows('audit_logs');
        Rls::appendOnly('audit_logs');

        // Settings — values are JSONB validated against a fixed schema (App\Settings\SettingsRegistry).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->jsonb('value');
            $table->timestamps();
        });

        Schema::create('subscriber_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->string('key', 120);
            $table->jsonb('value')->nullable();      // null + locked = lock the inherited value
            $table->boolean('locked')->default(false);
            $table->timestamps();
            $table->unique(['subscriber_id', 'key']);
        });
        Rls::enable('subscriber_settings');

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers');
            $table->foreignId('user_id')->constrained('users');
            $table->string('key', 120);
            $table->jsonb('value');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
        Rls::enableWithPlatformRows('user_settings');

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->string('action', 60);            // invoice.cancel | discount | payment.edit | trust.disburse | deposit.refund
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('payload');
            $table->foreignId('maker_id')->constrained('users');
            $table->foreignId('checker_id')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending');   // pending | approved | rejected
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('pending','approved','rejected'))");
        // Nobody approves what they entered themselves — enforced by the database as well (REQ-0069).
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_four_eyes CHECK (checker_id IS NULL OR checker_id <> maker_id)');
        Rls::enable('approval_requests');
        Rls::archiveOnly('approval_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('subscriber_settings');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('audit_logs');
    }
};
