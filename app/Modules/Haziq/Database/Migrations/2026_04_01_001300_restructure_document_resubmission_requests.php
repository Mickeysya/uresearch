<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalises last round's "flag one document" table into "flag one
     * document OR one whole section" — Admin can now return a specific
     * section (Project Details, GRA Details, Academic Qualification,
     * Working Experience, Publications) for the student to edit, not just a
     * missing file. target_type says which; target_label carries the
     * document's doc_type or the section's display name.
     */
    public function up(): void
    {
        Schema::rename('document_resubmission_requests', 'application_correction_requests');

        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->string('target_type', 20)->default('document')->after('application_id'); // VARCHAR, not ENUM
            $table->renameColumn('doc_type', 'target_label');
        });
    }

    public function down(): void
    {
        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->renameColumn('target_label', 'doc_type');
            $table->dropColumn('target_type');
        });

        Schema::rename('application_correction_requests', 'document_resubmission_requests');
    }
};
