<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * target_label has always been a human-readable string ("Section B —
     * Project Details — Project Title"), fine for display but useless for
     * matching back to the actual form field. Needed now that the student's
     * edit form highlights the exact flagged input, not just the section it
     * lives in — target_field carries the raw key (e.g. 'project_title'),
     * null when the whole section (or a document) was flagged rather than
     * one specific field.
     */
    public function up(): void
    {
        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->string('target_field', 100)->nullable()->after('target_type');
        });
    }

    public function down(): void
    {
        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->dropColumn('target_field');
        });
    }
};
