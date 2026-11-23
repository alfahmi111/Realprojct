<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M15 (owner order Q30, 2026-11-23) — phase-2 items:
 *  - identity_verifications: Nafath / Yaqeen / Wathq checks of tenants, owners, vendors and the office (REQ-0445…0447,
 *    REQ-0471, REQ-0472). Only a masked reference is kept, never the full national id returned by the source.
 *  - documents + document_views: the vault per property / building / unit / party, with expiry, versions and who
 *    uploaded and who viewed (REQ-0054, REQ-0265…REQ-0270). Files live on the private disk.
 *  - invoices.sadad_bill_number: SADAD bills (REQ-0467).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('subject_type', 20);                 // tenant | owner | vendor | subscriber
            $t->unsignedBigInteger('subject_id');
            $t->string('source', 10);                       // nafath | yaqeen | wathq
            $t->string('status', 20);                       // verified | mismatch | not_found | pending | rejected | expired
            $t->string('reference_masked', 40);             // e.g. ******0767 or CR ******4321
            $t->string('returned_name')->nullable();
            $t->jsonb('details')->default('{}');            // non-identifying facts: CR status and expiry, Nafath request id
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['subscriber_id', 'subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE identity_verifications ADD CONSTRAINT identity_verifications_source CHECK (source IN ('nafath','yaqeen','wathq'))");
        DB::statement("ALTER TABLE identity_verifications ADD CONSTRAINT identity_verifications_subject CHECK (subject_type IN ('tenant','owner','vendor','subscriber'))");
        Rls::enable('identity_verifications');

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('documentable_type', 20);            // property | building | space | owner | tenant
            $t->unsignedBigInteger('documentable_id');
            $t->foreignId('property_id')->nullable()->constrained('properties');   // for filtering and the statutory work order
            $t->string('category', 20);                     // ownership | license | certificate | party
            $t->string('kind', 40);
            $t->string('title_ar');
            $t->string('file_path');
            $t->string('mime', 100)->nullable();
            $t->date('expires_on')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('replaces_id')->nullable()->constrained('documents');
            $t->timestamp('superseded_at')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users');
            $t->foreignId('statutory_work_order_id')->nullable()->constrained('work_orders');
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
            $t->index(['subscriber_id', 'documentable_type', 'documentable_id']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_category CHECK (category IN ('ownership','license','certificate','party'))");
        Rls::enable('documents');

        Schema::create('document_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('document_id')->constrained('documents');
            $t->foreignId('user_id')->constrained('users');
            $t->string('action', 10)->default('view');     // view | download
            $t->timestamp('viewed_at');
        });
        Rls::enable('document_views');

        Schema::table('invoices', function (Blueprint $t) {
            $t->string('sadad_bill_number', 40)->nullable();
            $t->timestamp('sadad_issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['sadad_bill_number', 'sadad_issued_at']));
        Schema::dropIfExists('document_views');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('identity_verifications');
    }
};
