<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional photo of the property, set in the wizard or on the property page (owner request 2026-11-23). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->string('photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->dropColumn('photo_path');
        });
    }
};
