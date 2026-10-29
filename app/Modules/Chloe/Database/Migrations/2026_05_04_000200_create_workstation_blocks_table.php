<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per block (J2, N2, ...), for the same reason a room gets a photo:
 * the Home screen's block picker shows a photo for "which building", and
 * `workstation_locations.block` is just a bare string column with nothing
 * to hang a photo off. A block carries nothing else today, but it's a real
 * thing in its own right (a building, not an attribute of a room), so it
 * gets its own table rather than a hardcoded block-to-photo array in a view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('photo_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_blocks');
    }
};
