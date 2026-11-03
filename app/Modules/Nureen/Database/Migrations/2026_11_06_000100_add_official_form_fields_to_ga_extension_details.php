<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GA Extension Form (UTP/CGS/001-A2) is now generated from what the
 * student enters, instead of uploaded as a filled-in PDF -- see
 * LetterRenderer::gaExtensionForm().
 *
 * Section B's fields are real columns rather than additional_info keys:
 * the account number and IC/passport number need Laravel's `encrypted`
 * cast, which works on a column, not on one key inside a JSON array.
 * Everything here is nullable: drafts save half-filled, and applications
 * submitted before this have none of it. "Required" lives in
 * GaExtensionController::store()'s validation, built from
 * config('nureen.ga_extension.applicant_fields').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            // Section B
            $table->string('mode_of_study', 20)->nullable()->after('reason_for_extension');
            $table->date('admission_date')->nullable()->after('mode_of_study');
            $table->string('candidature_period', 60)->nullable()->after('admission_date');
            $table->string('level_of_study', 10)->nullable()->comment('msc or phd')->after('candidature_period');
            $table->string('contact_no', 20)->nullable()->after('level_of_study');
            $table->string('contact_email', 150)->nullable()->after('contact_no');
            $table->string('bank_name', 80)->nullable()->after('contact_email');
            $table->text('bank_account_no')->nullable()->comment('encrypted')->after('bank_name');
            $table->text('identity_number')->nullable()->comment('encrypted')->after('bank_account_no');
            $table->boolean('holds_visa')->nullable()->after('identity_number');
            $table->date('visa_expiry_date')->nullable()->after('holds_visa');

            // Section C, a list of rows
            $table->json('publications')->nullable()->after('visa_expiry_date');

            // Section D
            $table->timestamp('declaration_accepted_at')->nullable()->after('publications');

            // Section J, filled by CGS staff at the cgs_verify stage
            $table->json('cgs_remarks')->nullable()->after('declaration_accepted_at');
            $table->foreignId('cgs_remarks_by_id')->nullable()->after('cgs_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('cgs_remarks_at')->nullable()->after('cgs_remarks_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cgs_remarks_by_id');
            $table->dropColumn([
                'mode_of_study', 'admission_date', 'candidature_period', 'level_of_study', 'contact_no',
                'contact_email', 'bank_name', 'bank_account_no', 'identity_number', 'holds_visa',
                'visa_expiry_date', 'publications', 'declaration_accepted_at', 'cgs_remarks', 'cgs_remarks_at',
            ]);
        });
    }
};
