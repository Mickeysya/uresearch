<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GA's own allowance ledger -- deliberately separate from (never
     * merged with) allowance_payment_logs, confirmed explicitly
     * 2026-11-10: "ga ni letak actions lain. jangan sekali kan dengan
     * allowance gra satu excel." Much simpler than GRA's table: GA has
     * no Stage Gates, no termination, no accumulating running total --
     * one row per student, per application, per calendar month, each
     * one independent ("reset je every month"). confirmed_at/
     * confirmed_by/proof_document_id double as both "has this been
     * marked complete" and the record of who did it and with what
     * proof, the same idea as allowance_payment_confirmations but
     * folded into one row since there is no running total to keep
     * separate from the confirmation event.
     */
    public function up(): void
    {
        Schema::create('ga_allowance_payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->date('payment_month'); // always the 1st of the month
            $table->decimal('amount', 10, 2);

            $table->foreignId('proof_document_id')->nullable()->constrained('application_documents')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique(['application_id', 'payment_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_allowance_payment_logs');
    }
};
