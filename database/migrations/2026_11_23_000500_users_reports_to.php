<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office's organisation chart (owner's request 2026-11-23): each staff member reports to another member of the
 * same office. Departments follow the role (finance, leasing, maintenance); the reporting line is what can be moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('reports_to_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reports_to_id');
        });
    }
};
