<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_locations', function (Blueprint $table) {
            $table->id();

            // Mirrors CGS's own seat map reference (see docs/scope/ —
            // "Workstation seat map.pdf"): the portal's hierarchy is
            // Block -> Room -> Seats, and every room on that reference is
            // designated for one gender. male | female, VARCHAR for the same
            // reason as every other role/status column in this schema.
            $table->string('block', 10)->index();
            $table->string('room_code', 30)->unique();
            $table->string('gender', 10);

            // The room's own label, e.g. "PG LAB MALE 1.9" or "N2-03-01-02"
            // — copied verbatim from its seat map slide.
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_locations');
    }
};
