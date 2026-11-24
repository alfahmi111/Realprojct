<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The amount a SADAD bill was issued for (review M18 F12): after a partial payment the bill is reissued for what remains. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->bigInteger('sadad_amount_minor')->nullable());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('sadad_amount_minor'));
    }
};
