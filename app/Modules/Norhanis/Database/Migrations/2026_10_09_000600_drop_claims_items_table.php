<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The expense-line breakdown belonged to travel reimbursement.
        // Claims are journal-publication claims only, and total_claims is
        // now entered directly -- see ClaimsController::store().
        Schema::dropIfExists('claims_items');
    }

    public function down(): void
    {
        Schema::create('claims_items', function (Blueprint $table) {
            $table->id();
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
};
