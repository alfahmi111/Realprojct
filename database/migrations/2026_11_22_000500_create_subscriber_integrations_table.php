<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each subscriber's own integration accounts (REQ-0372): payment gateway merchant account in the manager's/owner's
 * name (REQ-0436), e-invoicing provider, WhatsApp, Odoo/Qoyod. Credentials are encrypted at rest (REQ-0506).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->string('integration', 40);
            $table->string('account_holder_name');        // must be the subscriber (or its owner), never the platform
            $table->text('credentials');                  // encrypted JSON
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['subscriber_id', 'integration']);
        });
        DB::statement("ALTER TABLE subscriber_integrations ADD CONSTRAINT subscriber_integrations_kind_check CHECK (integration IN ('payments','einvoicing','whatsapp','sms','accounting','document_reader'))");
        Rls::enable('subscriber_integrations');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_integrations');
    }
};
