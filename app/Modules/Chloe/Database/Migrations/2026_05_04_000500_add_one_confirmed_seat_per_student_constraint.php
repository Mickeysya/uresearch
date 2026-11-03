<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level half of "one student can hold only one seat" — see
 * WorkstationAllocator for the application-level half. Same generated-column
 * trick as workstation_pending_selections: NULL unless the row is this
 * student's currently-confirmed request, so a unique index on it only ever
 * constrains the one active row, not the student's whole history of
 * released/rejected requests. CASE WHEN, not MySQL's IF(), so it also parses
 * on the SQLite connection the test suite runs on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id_if_confirmed')
                ->virtualAs("CASE WHEN status = 'confirmed' THEN student_id ELSE NULL END")
                ->nullable()
                ->after('status');
        });

        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->unique('student_id_if_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropUnique(['student_id_if_confirmed']);
            $table->dropColumn('student_id_if_confirmed');
        });
    }
};
