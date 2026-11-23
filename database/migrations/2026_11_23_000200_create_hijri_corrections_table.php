<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual Hijri corrections (owner's decision 2026-11-23 on Q19, amending decision 13 — taken from the owner's previous
 * system special-center-system, table hijri_calendar_settings). Platform-level: one calendar for every subscriber,
 * entered by the platform admin when an official sighting differs from the Umm al-Qura calculation (±1 day, §7.6ج).
 * No personal data, so no RLS. Every change is written to the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hijri_corrections', function (Blueprint $t) {
            $t->id();
            $t->date('gregorian_date')->unique();
            $t->unsignedSmallInteger('hijri_year');
            $t->unsignedTinyInteger('hijri_month');
            $t->unsignedTinyInteger('hijri_day');
            $t->string('calculated_hijri', 10);
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hijri_corrections');
    }
};
