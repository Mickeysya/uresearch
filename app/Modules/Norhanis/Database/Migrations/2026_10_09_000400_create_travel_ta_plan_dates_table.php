<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_ta_plan_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ta_plan_id')->constrained('travel_ta_plans')->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_ta_plan_dates');
    }
};
