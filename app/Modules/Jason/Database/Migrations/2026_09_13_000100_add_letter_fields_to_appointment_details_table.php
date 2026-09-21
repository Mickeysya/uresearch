<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The letter body the Non-Executive CGS prepares between the Academic
        // Executive's endorsement and the Dean's approval. Everything here is
        // derived from existing records where one exists (candidate, matric
        // number, department, supervisor_id all live on users) -- only the
        // thesis title has nowhere else to come from, since no built module
        // captures it yet.
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->string('examiner_type', 20)->default('external')->after('examiner_expertise');

            $table->string('letter_ref_no', 60)->nullable()->after('examiner_type');
            $table->string('candidate_degree', 150)->nullable()->after('letter_ref_no');
            $table->string('candidate_programme', 150)->nullable()->after('candidate_degree');
            $table->string('supervisor_name', 150)->nullable()->after('candidate_programme');
            $table->string('thesis_title', 500)->nullable()->after('supervisor_name');

            // Null until CGS prepares it; doubles as the letter's own date, so
            // the Dean approves and the examiner receives the same date CGS saw.
            $table->timestamp('letter_prepared_at')->nullable()->after('thesis_title');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->dropColumn([
                'examiner_type',
                'letter_ref_no',
                'candidate_degree',
                'candidate_programme',
                'supervisor_name',
                'thesis_title',
                'letter_prepared_at',
            ]);
        });
    }
};
