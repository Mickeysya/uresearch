<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The examiner's field of expertise was required because the Chair picked
 * each examiner off a list that carried it. Selection now happens before
 * this module and the finalised list does not carry expertise at all --
 * nothing in the appointment letter or the evaluation report prints it --
 * so it becomes optional rather than being filled with an empty string that
 * claims to be a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->string('examiner_expertise', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->string('examiner_expertise', 150)->nullable(false)->default('')->change();
        });
    }
};
