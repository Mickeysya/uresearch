<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two ids the nomination chain knows an appointment by.
 *
 * The module that owns the examiner panel asks "has this examiner's pack
 * gone out?" in its own terms: the NOMINATION's application id, and the
 * examiner's id in the examiner records. Neither is stored here today --
 * `appointment_examiners.pool_examiner_id` points at this module's own
 * availability pool, which is a different table and a different id -- so
 * the question cannot be answered without keeping them.
 *
 * Both are nullable and carry no foreign key. They are copies of another
 * module's keys, written when a panel is read; a constraint across that
 * boundary would make this module's migrations depend on that module being
 * installed, which is exactly what the folder split avoids. Rows imported
 * before this migration, and rows matched by name rather than by id, simply
 * have null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->unsignedBigInteger('nomination_application_id')
                ->nullable()
                ->after('application_id')
                ->comment('The examiner nomination this list came from: the export\'s "Application No."');

            $table->index('nomination_application_id');
        });

        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->unsignedBigInteger('hani_examiner_id')
                ->nullable()
                ->after('pool_examiner_id')
                ->comment('examiners.id, as the nomination chain knows this examiner');

            $table->index('hani_examiner_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->dropIndex(['nomination_application_id']);
            $table->dropColumn('nomination_application_id');
        });

        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropIndex(['hani_examiner_id']);
            $table->dropColumn('hani_examiner_id');
        });
    }
};
