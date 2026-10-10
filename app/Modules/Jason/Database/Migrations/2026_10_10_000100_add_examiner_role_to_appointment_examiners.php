<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a panel member is the main examiner on their side or the backup.
 *
 * The finalised list names four: an internal main and backup, an external
 * main and backup. All four are appointed and all four get a pack, so the
 * letter itself does not change -- but a queue that cannot tell a backup
 * from a main cannot show CGS who is actually expected to return a report.
 *
 * Nullable: appointments imported before the list carried backups had one
 * examiner per side, and there is nothing to say about them either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->string('examiner_role', 10)->nullable()->after('examiner_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropColumn('examiner_role');
        });
    }
};
