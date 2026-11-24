<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Circulars to tenants carry images, video and PDF (owner request 2026-11-24): [{path, mime, name}] on the private disk. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->jsonb('attachments')->default('[]'));
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->dropColumn('attachments'));
    }
};
