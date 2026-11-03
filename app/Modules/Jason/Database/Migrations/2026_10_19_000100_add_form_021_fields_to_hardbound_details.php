<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The boxes on UTP/CGS/021 the candidate used to write in by hand.
 *
 * The form was downloaded, completed on paper and uploaded back; it is now
 * filled in on screen and the portal draws the form, so the four boxes it
 * left blank -- country, intake, passport/IC and date of birth -- have to be
 * captured like everything else.
 *
 * On this module's own detail table rather than `users`, which no module may
 * add columns to. That is also the better place for them: they are what this
 * candidate wrote on this submission, not a standing fact about the person,
 * so a later correction cannot rewrite a form already filed.
 *
 * `passport_no` is an identity-document number, the first this portal holds.
 * It is kept because UTP/CGS/021 asks for it, it is nullable, and it is
 * never printed anywhere but on that candidate's own form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->string('country', 60)->nullable()->after('programme');
            $table->string('intake', 30)->nullable()->after('country');
            $table->string('passport_no', 40)->nullable()->after('intake');
            $table->date('date_of_birth')->nullable()->after('passport_no');
        });
    }

    public function down(): void
    {
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->dropColumn(['country', 'intake', 'passport_no', 'date_of_birth']);
        });
    }
};
