<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an appointed examiner's pack cannot be emailed, in words.
 *
 * Two things stop a pack going out: the examiner records hold no address,
 * or the address they hold was reported as not working and is kept only so
 * CGS can see what was wrong with it. Either way the appointment is real --
 * the Dean still signs the letter, and it can go by post or by hand -- so
 * the examiner is appointed and the reason is carried alongside.
 *
 * Null means the address is fine. The sentence is shown to CGS as it
 * stands, which is also how the nomination chain hands one over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->string('email_problem', 300)
                ->nullable()
                ->after('examiner_email')
                ->comment('Why this pack cannot be emailed; null when the address is usable');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropColumn('email_problem');
        });
    }
};
