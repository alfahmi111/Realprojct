<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quick migration (wizard step 0, REQ-0122): extracted fields are staged with confidence; nothing is saved without confirmation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscriber_id')->constrained('subscribers');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('kind', 20);                  // ejar_pdf | excel | photo
            $t->string('file_path');
            $t->jsonb('extracted')->default('[]');   // [{field, value, confidence}]
            $t->jsonb('reviewed')->nullable();       // values after the manager's review
            $t->string('status', 20)->default('staged');   // staged | confirmed | discarded
            $t->jsonb('created_refs')->nullable();
            $t->timestamps();
        });
        Rls::enable('import_batches');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
