<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notifications (spec §7.6د, §5.8 reminders): templates, outbox with quiet hours / digest / escalation, usage counter. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('event', 60);
            $t->string('channel', 20);
            $t->string('locale', 2);
            $t->text('body');                               // with {tenant_name} {unit} {amount} {due_date} variables (REQ-0360)
            $t->string('provider_template_name')->nullable();
            $t->boolean('meta_approved')->default(false);  // WhatsApp templates need Meta approval
            $t->timestamps();
            $t->unique(['subscriber_id', 'event', 'channel', 'locale']);
        });

        Schema::create('notifications_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('event', 60);
            $t->string('channel', 20);                     // in_app | push | whatsapp | sms | email (REQ-0354)
            $t->string('recipient_type', 20);              // user | tenant
            $t->unsignedBigInteger('recipient_id');
            $t->string('locale', 2);
            $t->text('body');
            $t->jsonb('payload')->default('{}');
            $t->boolean('urgent')->default(false);
            $t->string('status', 30)->default('queued');   // queued | held_quiet_hours | in_digest | sent | read | failed | blocked_provider | blocked_template
            $t->timestampTz('send_after')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->unsignedTinyInteger('escalation_level')->default(0);
            $t->timestampTz('escalate_at')->nullable();
            $t->string('escalate_to_role', 40)->nullable();
            $t->string('subject_type', 40)->nullable();
            $t->string('subject_id', 40)->nullable();
            $t->string('provider_message_id')->nullable();
            $t->timestamps();
            $t->index(['status', 'send_after']);
        });

        Schema::create('message_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('month', 7);
            $t->string('channel', 20);
            $t->unsignedInteger('count')->default(0);
            $t->unique(['subscriber_id', 'month', 'channel']);
        });

        foreach (['notification_templates', 'notifications_outbox', 'message_usage'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['message_usage', 'notifications_outbox', 'notification_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
