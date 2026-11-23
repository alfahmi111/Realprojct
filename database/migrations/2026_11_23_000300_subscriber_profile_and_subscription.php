<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform admin's view of a subscriber (REQ-0065, REQ-0323): who the office is, its subscription period and the
 * agreed price, add-ons, and why it was suspended. Prices in the spec (§13) are "for discussion", so the price is what
 * the admin enters per subscriber — there is no default. Plus users.last_login_at for account support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $t) {
            $t->string('commercial_registration', 20)->nullable();
            $t->string('vat_number', 20)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('contact_name')->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->string('contact_email')->nullable();
            $t->string('billing_cycle', 10)->nullable();          // monthly | annual
            $t->date('subscription_starts_on')->nullable();
            $t->date('subscription_ends_on')->nullable();
            $t->bigInteger('price_minor')->nullable();            // agreed price per cycle, halalas
            $t->jsonb('addons')->default('[]');                   // REQ-0638
            $t->text('suspended_reason')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->text('notes')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_login_at'));
        Schema::table('subscribers', fn (Blueprint $t) => $t->dropColumn(['commercial_registration', 'vat_number', 'city', 'contact_name', 'contact_phone', 'contact_email',
            'billing_cycle', 'subscription_starts_on', 'subscription_ends_on', 'price_minor', 'addons', 'suspended_reason', 'suspended_at', 'notes']));
    }
};
