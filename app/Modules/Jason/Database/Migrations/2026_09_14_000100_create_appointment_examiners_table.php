<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A nomination is a candidate's examiner panel -- at least one
        // internal and one external examiner -- not a single examiner, so
        // the examiner fields move off appointment_details (one row per
        // application) into their own table (one row per examiner).
        Schema::create('appointment_examiners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('examiner_type', 20);
            $table->string('examiner_name', 150);
            $table->string('examiner_institution', 150);
            $table->text('examiner_address')->nullable();
            $table->string('examiner_email', 150);
            $table->string('examiner_expertise', 255);

            // Set by CGS at preparation; the series differs by type.
            $table->string('letter_ref_no', 60)->nullable();

            $table->timestamps();
        });

        // Carry existing single-examiner nominations across as one-examiner
        // panels so nothing already in flight is lost.
        $now = now();

        foreach (DB::table('appointment_details')->get() as $row) {
            DB::table('appointment_examiners')->insert([
                'application_id' => $row->application_id,
                'examiner_type' => $row->examiner_type ?? 'external',
                'examiner_name' => $row->examiner_name,
                'examiner_institution' => $row->examiner_institution,
                'examiner_address' => $row->examiner_address,
                'examiner_email' => $row->examiner_email,
                'examiner_expertise' => $row->examiner_expertise,
                'letter_ref_no' => $row->letter_ref_no,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('appointment_details', function (Blueprint $table) {
            $table->dropColumn([
                'examiner_name', 'examiner_institution', 'examiner_address',
                'examiner_email', 'examiner_expertise', 'examiner_type', 'letter_ref_no',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->string('examiner_name', 150)->nullable()->after('application_id');
            $table->string('examiner_institution', 150)->nullable()->after('examiner_name');
            $table->text('examiner_address')->nullable()->after('examiner_institution');
            $table->string('examiner_email', 150)->nullable()->after('examiner_address');
            $table->string('examiner_expertise', 255)->nullable()->after('examiner_email');
            $table->string('examiner_type', 20)->default('external')->after('examiner_expertise');
            $table->string('letter_ref_no', 60)->nullable()->after('examiner_type');
        });

        // Only the first examiner of each panel can go back onto the single-row shape.
        foreach (DB::table('appointment_examiners')->orderBy('id')->get()->groupBy('application_id') as $applicationId => $rows) {
            $first = $rows->first();

            DB::table('appointment_details')->where('application_id', $applicationId)->update([
                'examiner_name' => $first->examiner_name,
                'examiner_institution' => $first->examiner_institution,
                'examiner_address' => $first->examiner_address,
                'examiner_email' => $first->examiner_email,
                'examiner_expertise' => $first->examiner_expertise,
                'examiner_type' => $first->examiner_type,
                'letter_ref_no' => $first->letter_ref_no,
            ]);
        }

        Schema::dropIfExists('appointment_examiners');
    }
};
