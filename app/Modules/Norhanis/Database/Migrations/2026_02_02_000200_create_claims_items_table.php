<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims_items', function (Blueprint $table) {
            $table->id();

            // Linked to claims_details, NOT applications — a claim can have many
            // expense line items (different dates, different travel legs), so
            // this is the one-to-many side. See ClaimsDetail::items().
            $table->foreignId('claims_detail_id')->constrained()->cascadeOnDelete();

            $table->date('item_date');
            $table->string('travel_from', 150)->nullable();
            $table->string('travel_to', 150)->nullable();
            $table->decimal('flight_train_amount', 10, 2)->default(0);
            $table->decimal('meal_allowance', 10, 2)->default(0);
            $table->decimal('lodging_amount', 10, 2)->default(0);
            $table->decimal('misc_amount', 10, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims_items');
    }
};