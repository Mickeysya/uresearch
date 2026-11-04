<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_ta_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Fixed per semester, not trusted from the form -- see
            // TravelController::TA_HOURS_REQUIRED.
            $table->unsignedSmallInteger('total_hours_required');
            $table->decimal('hours_completed', 5, 2);
            $table->decimal('hours_during_travel', 5, 2);

            // completed_before_travel | rescheduled_after_travel |
            // no_ta_activities_affected | replacement_arrangement
            $table->string('management_method', 40);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_ta_plans');
    }
};
