<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform-level token → subscriber map for the public offer link (no personal data; the offer itself stays under RLS). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_offer_tokens', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary();
            $t->unsignedBigInteger('subscriber_id');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_offer_tokens');
    }
};
