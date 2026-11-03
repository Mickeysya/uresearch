<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_location_id')->constrained()->cascadeOnDelete();
            $table->string('seat_code', 20);

            // available | occupied | reserved | disabled — see
            // Workstation::STATUS_* and docs/architecture.md on why this is a
            // VARCHAR and not an ENUM.
            $table->string('status', 20)->default('available')->index();

            // The seat map reference groups a room's seats into desk
            // clusters rather than one uniform grid, and the cluster shape
            // differs per room. `cluster` names which group a seat belongs
            // to; `position` is its left-to-right, top-to-bottom order within
            // that cluster as printed on the reference — seat numbers are
            // not printed in a consistent direction (some rows count down),
            // so this can't be derived by sorting seat_code.
            $table->string('cluster', 60)->nullable();
            $table->unsignedSmallInteger('position')->nullable();

            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['workstation_location_id', 'seat_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstations');
    }
};
