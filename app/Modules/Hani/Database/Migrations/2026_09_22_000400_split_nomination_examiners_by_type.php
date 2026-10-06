<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four examiner slots per nomination instead of two.
 *
 * The supervisor now names a main AND a backup on both sides -- internal and
 * external -- rather than one main and one backup of whichever type. The old
 * pair could not express that: a nomination with two internals and no
 * external looked identical in the schema to a correct one, and the panel a
 * viva actually needs (one of each, each with a reserve) could not be filed
 * at all.
 *
 * The internal side is additionally constrained to the candidate's own
 * department, enforced in ExaminerNominationController::store() rather than
 * here -- a foreign key cannot express "the same department as the student on
 * the application this row belongs to", and a CHECK constraint would fire
 * long after the supervisor could be told why.
 *
 * Existing rows are carried across by TYPE: whatever `main_examiner_id`
 * pointed at lands in the internal or external main slot depending on what
 * that examiner actually is. Nothing is lost, and down() puts it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examiner_nominations', function (Blueprint $table) {
            foreach (['internal_main_id', 'internal_backup_id', 'external_main_id', 'external_backup_id'] as $column) {
                $table->foreignId($column)->nullable()->after('application_id')
                    ->constrained('examiners')->restrictOnDelete();
            }
        });

        // One statement per (old slot, examiner type) pair: four in total, all
        // driven by the examiner's own `type`, so a nomination that was filed
        // with two internals keeps both and does not silently become one.
        foreach (['main_examiner_id' => 'main', 'backup_examiner_id' => 'backup'] as $old => $slot) {
            foreach (['internal', 'external'] as $type) {
                DB::table('examiner_nominations')
                    ->join('examiners', 'examiners.id', '=', "examiner_nominations.{$old}")
                    ->where('examiners.type', $type)
                    ->update(["examiner_nominations.{$type}_{$slot}_id" => DB::raw("examiner_nominations.{$old}")]);
            }
        }

        Schema::table('examiner_nominations', function (Blueprint $table) {
            $table->dropForeign(['main_examiner_id']);
            $table->dropForeign(['backup_examiner_id']);
            $table->dropColumn(['main_examiner_id', 'backup_examiner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('examiner_nominations', function (Blueprint $table) {
            $table->foreignId('main_examiner_id')->nullable()->after('application_id')
                ->constrained('examiners')->restrictOnDelete();
            $table->foreignId('backup_examiner_id')->nullable()->after('main_examiner_id')
                ->constrained('examiners')->restrictOnDelete();
        });

        // Internal first, external only where the internal slot was empty:
        // the old pair holds two examiners and the new one holds four, so
        // going back cannot keep everything. The internal panel is the half
        // that was always there.
        DB::table('examiner_nominations')->update([
            'main_examiner_id' => DB::raw('COALESCE(internal_main_id, external_main_id)'),
            'backup_examiner_id' => DB::raw('COALESCE(internal_backup_id, external_backup_id)'),
        ]);

        Schema::table('examiner_nominations', function (Blueprint $table) {
            foreach (['internal_main_id', 'internal_backup_id', 'external_main_id', 'external_backup_id'] as $column) {
                $table->dropForeign([$column]);
                $table->dropColumn($column);
            }
        });
    }
};
