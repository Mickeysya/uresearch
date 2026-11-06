<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A distinct event from Stage Gate completion — completing Stage 1
     * (RPD) only ever reveals that a lump sum is owed; it never means the
     * money has actually been disbursed. Admin or CGS record THIS
     * separately, with a proof-of-payment document, the moment they've
     * actually paid it out. Confirmed explicitly 2026-10-23: the running
     * withheld/backdated total keeps growing every month a completed
     * gate's payment still isn't confirmed — only a confirmation resets
     * it, starting the month after. One application can carry more than
     * one of these over its lifetime (withheld, settled, withheld again
     * later), so this is its own table, not a column on
     * allowance_payment_logs.
     */
    public function up(): void
    {
        Schema::create('allowance_payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->date('confirmed_month');
            $table->decimal('amount', 10, 2);
            $table->foreignId('proof_document_id')->nullable()->constrained('application_documents')->nullOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_payment_confirmations');
    }
};
