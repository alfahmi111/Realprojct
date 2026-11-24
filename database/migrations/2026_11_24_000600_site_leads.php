<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo requests from the public website (M19). Platform-level (no subscriber yet), read by the platform admin only.
 * Personal data kept with explicit consent; the platform admin archives a request when it is handled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_leads', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('organization', 160)->nullable();
            $t->string('phone', 20);
            $t->string('email', 160)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('units', 20)->nullable();        // range chosen on the form
            $t->string('message', 1000)->nullable();
            $t->timestamp('consented_at');
            $t->string('status', 20)->default('new');   // new | contacted | archived
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_leads');
    }
};
