<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every seat/key lifecycle action. No model here
 * ever updates or deletes a row -- see WorkstationHistory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_histories', function (Blueprint $table) {
            $table->id();
            $table->string('block', 10);
            $table->string('room_code', 30);
            $table->string('seat_number', 20);

            // Snapshots, not foreign keys -- a History row must still read
            // sensibly after the student account or seat it names is long
            // gone, the same reason approval_history snapshots names
            // elsewhere in this app.
            $table->string('student_name', 150)->nullable();
            $table->string('student_matric_no', 50)->nullable();

            // student | cgs | system
            $table->string('actor_type', 20);
            $table->string('actor_name', 150);

            $table->string('action', 60);
            $table->string('reason', 255)->nullable();
            $table->text('remark')->nullable();
            $table->string('before_state', 30)->nullable();
            $table->string('after_state', 30)->nullable();

            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_histories');
    }
};
