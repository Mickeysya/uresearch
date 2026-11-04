<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 items d, f and h: the re-viva files that name or come from the
 * examiners, kept OUT of DocumentStore.
 *
 *   correction_letter           one per current main examiner (generated)
 *   re_evaluation_report        the signed UTP/CGS/024 (uploaded by the AE)
 *   confirmation_of_correction  pre-filled for signatures (generated)
 *
 * DocumentStore files hang off an application the student owns, and Core
 * shows the student every one of them; these must stay staff-only. Private
 * disk, random names, downloaded through a staff route that logs each view
 * with activity('document'), as the viva examiner reports do
 * (SHARED_CHANGES.md, section 8).
 *
 * Also stamps the cycle when the letters go out: the panel's twenty working
 * days, and the stage-2 reminders, run from sent_to_panel_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_staff_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_viva_detail_id')->constrained('re_viva_details')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('seat', 30)->nullable()->comment('internal_main_id | external_main_id');
            $table->foreignId('examiner_id')->nullable()->constrained('examiners')->nullOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->timestamp('emailed_at')->nullable();
            $table->string('email_skipped_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['re_viva_detail_id', 'kind']);
        });

        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->timestamp('sent_to_panel_at')->nullable()->after('logged_outside_window');
            $table->foreignId('sent_to_panel_by_id')->nullable()->after('sent_to_panel_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropForeign(['sent_to_panel_by_id']);
            $table->dropColumn(['sent_to_panel_at', 'sent_to_panel_by_id']);
        });

        Schema::dropIfExists('re_viva_staff_documents');
    }
};
