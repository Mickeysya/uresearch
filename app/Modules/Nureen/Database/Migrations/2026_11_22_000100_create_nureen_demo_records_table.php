<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "demo flag" for `nureen:demo-fresh`: one row per record that command
 * created, so `--reset` removes exactly those and nothing else. Kept in its
 * own table rather than as a column on applications (hard rule 2), and
 * rather than as text inside the records, because some of what it creates
 * (attendance rows, a GA/GRA appointment record, a student profile) has no
 * free-text field to mark.
 *
 * Holds only table names and ids, so it never needs to change when the
 * tables it points at do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nureen_demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_table', 64);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['record_table', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nureen_demo_records');
    }
};
