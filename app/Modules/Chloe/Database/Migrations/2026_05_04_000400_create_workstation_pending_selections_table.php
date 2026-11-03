<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_pending_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();

            // pending | confirmed | expired — see WorkstationPendingSelection.
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        // MySQL has no partial/filtered unique index, so this is the
        // standard workaround: a generated column that's NULL unless the
        // row is the student's active pending selection, with a unique
        // index on THAT — MySQL (and SQLite, which the test suite runs on)
        // allow any number of NULLs in a unique index, so only one actual
        // 'pending' row per student is constrained, not one row ever. CASE
        // WHEN rather than MySQL's IF() because it has to parse on both.
        // This is the database-level half of "one student, one pending
        // selection"; WorkstationAllocator enforces the same rule in the
        // application before it ever reaches this constraint.
        Schema::table('workstation_pending_selections', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id_if_pending')
                ->virtualAs("CASE WHEN status = 'pending' THEN student_id ELSE NULL END")
                ->nullable()
                ->after('status');
        });

        Schema::table('workstation_pending_selections', function (Blueprint $table) {
            $table->unique('student_id_if_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_pending_selections');
    }
};
