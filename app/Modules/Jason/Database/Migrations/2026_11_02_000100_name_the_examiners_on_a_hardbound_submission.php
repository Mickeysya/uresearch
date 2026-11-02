<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The candidate names their own examiners on the submission.
 *
 * They were read back from the appointment pack -- the panel the Dean
 * approved -- and everyone on it was emailed a signing link. The viva
 * happens before the hardbound submission, so by this point the candidate
 * knows which two examiners actually examined them, and says so.
 *
 * Held as both an id and a snapshot of the name and email. The id is what
 * the examiner records are looked up by; the snapshot is what the form
 * prints and what the signing link is addressed to, so editing or removing
 * a record later cannot rewrite a submission already filed or break a link
 * already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            foreach (['internal', 'external'] as $slot) {
                $table->unsignedBigInteger("{$slot}_examiner_id")->nullable()->after('supervisor_name');
                $table->string("{$slot}_examiner_name", 150)->nullable()->after("{$slot}_examiner_id");
                $table->string("{$slot}_examiner_email", 150)->nullable()->after("{$slot}_examiner_name");
            }
        });

        // A signature is now against a slot on this submission, not a row in
        // the appointment pack. Carry the existing ones across before the
        // old column goes, so the forms they signed keep their signatures.
        Schema::table('hardbound_examiner_signatures', function (Blueprint $table) {
            $table->string('examiner_slot', 10)->nullable()->after('application_id');
            $table->string('examiner_name', 150)->nullable()->after('examiner_slot');
            $table->string('examiner_email', 150)->nullable()->after('examiner_name');
        });

        foreach (DB::table('hardbound_examiner_signatures')->get() as $signature) {
            $examiner = DB::table('appointment_examiners')
                ->where('id', $signature->appointment_examiner_id)
                ->first();

            if (! $examiner) {
                continue;
            }

            DB::table('hardbound_examiner_signatures')->where('id', $signature->id)->update([
                'examiner_slot' => $examiner->examiner_type,
                'examiner_name' => $examiner->examiner_name,
                'examiner_email' => $examiner->examiner_email,
            ]);
        }

        Schema::table('hardbound_examiner_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_examiner_id');
        });
    }

    public function down(): void
    {
        Schema::table('hardbound_examiner_signatures', function (Blueprint $table) {
            $table->foreignId('appointment_examiner_id')->nullable()
                ->constrained('appointment_examiners')->cascadeOnDelete();
            $table->dropColumn(['examiner_slot', 'examiner_name', 'examiner_email']);
        });

        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->dropColumn([
                'internal_examiner_id', 'internal_examiner_name', 'internal_examiner_email',
                'external_examiner_id', 'external_examiner_name', 'external_examiner_email',
            ]);
        });
    }
};
