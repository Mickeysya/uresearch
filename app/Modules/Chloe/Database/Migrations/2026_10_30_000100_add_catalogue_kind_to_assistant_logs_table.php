<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The router's metadata log: lookup already holds the catalogue id (a
 * lookup name, "guidance:<topic>", an action id or out_of_scope); this
 * adds its kind (lookup | guidance | action | out_of_scope), null for a
 * row that is not a catalogue answer (a privacy refusal, a budget stop).
 * Never any question or answer text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('catalogue_kind', 20)->nullable()->after('lookup');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn('catalogue_kind');
        });
    }
};
