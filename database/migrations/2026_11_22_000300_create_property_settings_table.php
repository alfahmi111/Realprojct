<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Property/building-level overrides (REQ-0325). The FK to properties is added with the property model (M2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->unsignedBigInteger('property_id');
            $table->string('key', 120);
            $table->jsonb('value');
            $table->timestamps();
            $table->unique(['property_id', 'key']);
        });
        Rls::enable('property_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('property_settings');
    }
};
