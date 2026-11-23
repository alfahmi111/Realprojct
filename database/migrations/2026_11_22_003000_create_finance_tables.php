<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance (spec §9): invoices with a tax-treatment code on every line (REQ-0420), payments and FIFO allocations
 * (REQ-0188, REQ-0143), double-entry journal for trust accounting (REQ-0428…REQ-0433), reconciliation work queue
 * (REQ-0434, REQ-0435), document numbering. All amounts are integer halalas. Nothing financial is ever deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('kind', 30);                 // invoice | receipt | contract | credit_note
            $t->unsignedInteger('year');
            $t->unsignedBigInteger('last_number')->default(0);
            $t->unique(['subscriber_id', 'kind', 'year']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('number', 60);
            $t->string('kind', 30);                          // rent | maintenance_recharge | service | management_fee
            $t->string('seller_type', 20);                   // owner | manager
            $t->foreignId('seller_owner_id')->nullable()->constrained('owners');
            $t->foreignId('tenant_id')->nullable()->constrained('tenants');
            $t->foreignId('buyer_owner_id')->nullable()->constrained('owners');   // management fee invoices: manager → owner
            $t->foreignId('lease_id')->nullable()->constrained('leases');
            $t->foreignId('billing_schedule_line_id')->nullable()->unique()->constrained('billing_schedule_lines');
            $t->foreignId('property_id')->nullable()->constrained('properties');
            $t->date('issue_date');
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('ejar_contract_number', 60)->nullable();   // REQ-0424
            $t->string('unit_code', 40)->nullable();
            $t->string('buyer_vat_number', 20)->nullable();
            $t->jsonb('buyer_national_address')->nullable();
            $t->string('einvoice_type', 10);                      // b2b | b2c
            $t->bigInteger('subtotal_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->string('status', 20)->default('issued');          // issued | cancelled
            $t->string('einvoice_uuid', 64)->nullable();
            $t->string('einvoice_status', 20)->nullable();        // cleared | reported | pending | failed
            $t->text('einvoice_qr')->nullable();
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
            $t->unique(['subscriber_id', 'number']);
        });
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_totals CHECK (total_minor = subtotal_minor + tax_minor AND subtotal_minor >= 0 AND tax_minor >= 0)');

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->string('description_ar');
            $t->string('description_en')->nullable();
            $t->bigInteger('net_minor');
            $t->string('tax_code', 40);                 // رمز المعاملة الضريبية (REQ-0420)
            $t->unsignedInteger('tax_rate_bp');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_totals CHECK (total_minor = net_minor + tax_minor AND net_minor >= 0 AND tax_minor >= 0)');

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->string('receipt_number', 60);
            $t->bigInteger('amount_minor');
            $t->string('method', 20);                  // mada | apple_pay | card | sadad | bank_transfer | cash
            $t->string('source', 20);                  // manual | gateway | bank_csv
            $t->date('received_on');
            $t->string('reference')->nullable();
            $t->string('gateway_payment_id')->nullable();
            $t->string('paid_to', 20);                 // manager | owner — never the platform (REQ-0436)
            $t->foreignId('recorded_by')->nullable()->constrained('users');
            $t->string('status', 20)->default('recorded');   // recorded | reversed
            $t->timestamps();
            $t->timestamp('archived_at')->nullable();
            $t->unique(['subscriber_id', 'receipt_number']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_paid_to CHECK (paid_to IN ('manager','owner'))");

        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('payment_id')->constrained('payments');
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->bigInteger('amount_minor');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount CHECK (amount_minor > 0)');

        // Double-entry journal. Accounts are keyed strings: owner_trust:{owner_id}, manager_revenue, deposit_liability:{lease_id}, ...
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->date('entry_date');
            $t->string('kind', 40);
            $t->string('memo_ar');
            $t->string('source_type', 60)->nullable();
            $t->string('source_id', 40)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('owners');
            $t->foreignId('property_id')->nullable()->constrained('properties');
            $t->string('external_ref')->nullable();     // accounting system reference after export
            $t->timestamps();
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('journal_entry_id')->constrained('journal_entries');
            $t->string('account', 80);
            $t->foreignId('owner_id')->nullable()->constrained('owners');
            $t->bigInteger('debit_minor')->default(0);
            $t->bigInteger('credit_minor')->default(0);
            $t->timestamps();
            $t->index(['subscriber_id', 'account']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK (debit_minor >= 0 AND credit_minor >= 0 AND (debit_minor = 0) <> (credit_minor = 0))');
        // Every entry balances: checked at commit time by a deferred constraint trigger.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_journal_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE d bigint; c bigint;
            BEGIN
                SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) INTO d, c FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
                IF d <> c THEN RAISE EXCEPTION 'journal entry % is unbalanced (debit % / credit %)', NEW.journal_entry_id, d, c; END IF;
                RETURN NULL;
            END $$
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION app_journal_balanced()');

        Schema::create('reconciliation_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->string('kind', 40);       // registered_lease_without_invoice | invoice_without_lease | invoice_on_expired_lease | amount_mismatch | unallocated_payment | ejar_contract_unknown | ejar_status_mismatch
            $t->string('subject_type', 40);
            $t->string('subject_id', 60);
            $t->jsonb('details')->default('{}');
            $t->date('detected_on');
            $t->string('status', 20)->default('open');   // open | resolved
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->text('resolution_note')->nullable();
            $t->timestamps();
            $t->unique(['subscriber_id', 'kind', 'subject_type', 'subject_id', 'status']);
        });

        foreach (['document_sequences', 'invoices', 'invoice_lines', 'payments', 'payment_allocations', 'journal_entries', 'journal_lines', 'reconciliation_issues'] as $table) {
            Rls::enable($table);
        }
        foreach (['invoices', 'payments'] as $table) {
            Rls::archiveOnly($table);
        }
        foreach (['invoice_lines', 'payment_allocations', 'journal_entries', 'journal_lines'] as $table) {
            Rls::appendOnly($table);   // corrections are new reversing entries, never edits
        }

        // VAT rate is a regulatory value → policies table (15% as written in spec §9.1).
        DB::table('policies')->insert([
            'kind' => 'vat_rate', 'region' => null, 'city' => null, 'effective_from' => '2026-11-22', 'effective_to' => null,
            'code' => 'KSA-VAT-STANDARD', 'title_ar' => 'نسبة ضريبة القيمة المضافة الأساسية', 'title_en' => 'Standard VAT rate',
            'payload' => json_encode(['rate_bp' => 1500]), 'source_url' => 'https://zatca.gov.sa/ar/MediaCenter/News/Pages/News-390.aspx',
            'needs_official_check' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['reconciliation_issues', 'journal_lines', 'journal_entries', 'payment_allocations', 'payments', 'invoice_lines', 'invoices', 'document_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS app_journal_balanced() CASCADE');
    }
};
