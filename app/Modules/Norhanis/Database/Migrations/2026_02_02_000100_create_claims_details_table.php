<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('purpose_of_claim', 255);
            $table->string('bank_account_no', 30);

            // total_claim_amount and claim_balance are NEVER written from request
            // input directly — ClaimsController sums claims_items server-side and
            // computes claim_balance = total_claim_amount - less_cash_advance.
            // See ClaimsController::store().
            $table->decimal('total_claim_amount', 10, 2)->default(0);
            $table->decimal('less_cash_advance', 10, 2)->default(0);
            $table->decimal('claim_balance', 10, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims_details');
    }
};