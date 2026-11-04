<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin flags one document as wrong/missing without rejecting the whole
     * application. The application itself never moves — status/current_stage
     * stay exactly where WorkflowEngine left them, because this table (and
     * the controller actions that write it) never call submit()/decide() at
     * all. The student uploads a replacement under the same doc_type
     * (DocumentStore keeps the old one; nothing is overwritten), which
     * resolves the request. CGS then approves/rejects as normal, whenever
     * they're satisfied — the application was never restarted.
     */
    public function up(): void
    {
        Schema::create('document_resubmission_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('doc_type', 100); // matches ApplicationDocument.doc_type
            $table->text('remarks');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_document_id')->nullable()->constrained('application_documents')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_resubmission_requests');
    }
};
