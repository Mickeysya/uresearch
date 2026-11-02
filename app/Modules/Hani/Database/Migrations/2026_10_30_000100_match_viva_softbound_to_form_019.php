<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The softbound record, matched to the real CGS Online Form #019
 * (UTP/CGS/V3/VV/019).
 *
 * Added: what the form asks that UResearch does not already hold. Matric,
 * name, department, programme, supervisor and AE are NOT copied in; they are
 * read from the student's own record. Nullable, because attempts filed
 * before this have none of it.
 *
 * Dropped: the three self-declared ticks (bilingual abstract, certified
 * copies, before candidacy ends). The real form has none of them; its
 * Agreement declaration takes their place. Similarity % and word count stay:
 * they are not on the form either, but they are what lets the system enforce
 * the Handbook's 25% and word-limit rules instead of leaving them to the AE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_softbound_details', function (Blueprint $table) {
            // Section 1: Student Details
            $table->string('field_of_study', 255)->nullable()->after('viva_application_id');
            $table->string('contact_number', 30)->nullable()->after('field_of_study');
            $table->string('personal_email', 150)->nullable()->after('contact_number');

            // Section 2: Submission Details
            $table->string('session', 9)->nullable()->after('personal_email');
            $table->unsignedSmallInteger('year')->nullable()->after('session');
            $table->string('thesis_title', 255)->nullable()->after('year');

            // Section 3: Approval & Notifications. Supervisor and AE are known
            // already; the co-supervisor is not stored anywhere else.
            $table->string('co_supervisor_email', 150)->nullable()->after('word_count');

            // Section 4: Agreement
            $table->boolean('declaration_accepted')->default(false)->after('co_supervisor_email');

            $table->dropColumn(['abstract_bilingual', 'copies_certified', 'before_candidacy_end']);
        });
    }

    public function down(): void
    {
        Schema::table('viva_softbound_details', function (Blueprint $table) {
            $table->boolean('abstract_bilingual')->default(false);
            $table->boolean('copies_certified')->default(false);
            $table->boolean('before_candidacy_end')->default(false);

            $table->dropColumn([
                'field_of_study', 'contact_number', 'personal_email', 'session', 'year',
                'thesis_title', 'co_supervisor_email', 'declaration_accepted',
            ]);
        });
    }
};
