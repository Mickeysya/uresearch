<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts the conflict-of-interest pre-check needs (UTP/CGS/026), and an
 * email that may now be missing.
 *
 * current_utp_appointment -- an external examiner who also holds a UTP post
 *   ("Adjunct Professor", "Visiting Lecturer", "IAP") is not independent of
 *   UTP. Free text, as CGS writes it.
 * former_utp_until -- when an external examiner stopped being UTP staff or a
 *   UTP student. Inside the window in config('hani.coi.former_utp_years') it
 *   is a warning.
 *
 * Both are external-only (Examiner::EXTERNAL_FIELDS), like the 2026_09_17
 * columns.
 *
 * email becomes nullable: CGS's own pool file has no email column (they keep
 * emails separately), so an imported examiner may arrive without one. The
 * unique index stays -- MySQL allows any number of NULLs under it -- and
 * everything that emails an examiner skips one without an address and says
 * so. See Examiner::needsEmail().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->string('current_utp_appointment', 150)->nullable()->after('phd_graduated');
            $table->date('former_utp_until')->nullable()->after('current_utp_appointment');
            $table->string('email', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->dropColumn(['current_utp_appointment', 'former_utp_until']);
            // Not restored to NOT NULL: rows imported without an email would
            // make the rollback fail, and a rollback must not need data loss.
        });
    }
};
