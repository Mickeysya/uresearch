<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A representative photo for the room, shown on the room list and the seat
 * map so a student sees what they're picking into, not just a code. Nullable
 * and defaulted to a generic illustration by WorkstationLocation::photoUrl()
 * rather than required -- CGS can attach a real photo per room later without
 * another migration, and nothing breaks for a room that never gets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_locations', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_locations', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
