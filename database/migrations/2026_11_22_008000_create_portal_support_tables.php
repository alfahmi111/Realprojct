<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Portals support: accounting exports (REQ-0473), announcements (REQ-0278), offline sync idempotency (REQ-0495), waitlist source on offers (REQ-0292). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('journal_entry_id')->unique()->constrained('journal_entries');
            $t->string('system', 20);              // qoyod | zoho | odoo
            $t->string('external_ref');
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('building_id')->nullable()->constrained('buildings');
            $t->string('title_ar');
            $t->text('body_ar');
            $t->timestampTz('published_at');
            $t->timestamps();
        });
        Schema::create('offline_sync_ops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('client_op_id');
            $t->string('op', 40);
            $t->jsonb('result')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'client_op_id']);
        });
        Schema::table('lease_offers', fn (Blueprint $t) => $t->foreignId('source_prospect_id')->nullable()->constrained('prospects'));
        foreach (['accounting_exports', 'announcements', 'offline_sync_ops'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('lease_offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('source_prospect_id'));
        foreach (['offline_sync_ops', 'announcements', 'accounting_exports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
