<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dismissal process (simplified): CGS exports the live list of expired candidacies to Excel, the Registry processes it elsewhere, and the
 * Registry or CGS uploads the result. No keep status, no per-row reason, no batch lock.
 *
 *  - candidacy_dismissal_exports: one row per student per export (the newest exported_at is shown on the list).
 *  - candidacy_dismissal_imports / _rows: one row per upload, and one per student it dismissed.
 *  - demo_student_profiles (ours): candidacy_only marks demo students that Workstation never sees; dismissed_at / dismissed_by_id are the
 *    demo student's dismissal (a real student's live on study_candidacies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_student_profiles', function (Blueprint $table) {
            $table->boolean('candidacy_only')->default(false)->index();
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by_id')->nullable();
        });

        Schema::create('candidacy_dismissal_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_id')->nullable()->constrained('study_candidacies')->cascadeOnDelete();
            $table->foreignId('demo_profile_id')->nullable()->constrained('demo_student_profiles')->cascadeOnDelete();
            $table->timestamp('exported_at');
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('candidacy_dismissal_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 255);
            $table->timestamp('uploaded_at');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('dismissed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('summary')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('candidacy_dismissal_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('candidacy_dismissal_imports')->cascadeOnDelete();
            $table->foreignId('candidacy_id')->nullable()->constrained('study_candidacies')->nullOnDelete();
            $table->foreignId('demo_profile_id')->nullable()->constrained('demo_student_profiles')->nullOnDelete();
            $table->string('student_code', 40);
            $table->timestamp('dismissed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_dismissal_import_rows');
        Schema::dropIfExists('candidacy_dismissal_imports');
        Schema::dropIfExists('candidacy_dismissal_exports');

        Schema::table('demo_student_profiles', function (Blueprint $table) {
            $table->dropColumn(['candidacy_only', 'dismissed_at', 'dismissed_by_id']);
        });
    }
};
