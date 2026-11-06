<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per student, per application, per calendar month — plain
     * calendar months, not the UTP form's 16th-to-15th claim window
     * (deliberately dropped this round; see conversation record).
     *
     * status mirrors the owning stage gate's allowance_status at the time
     * the row was written, but is a durable log rather than a live read of
     * stage_gates: a month that was 'withheld' and is later completed within
     * grace gets its own row's status flipped to 'backdated' rather than
     * losing the fact that it was ever withheld.
     */
    public function up(): void
    {
        Schema::create('allowance_payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_gate_id')->nullable()->constrained('stage_gates')->nullOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->date('payment_month'); // always the 1st of the month
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('eligible'); // VARCHAR, not ENUM -- see Core\Support\Role
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['application_id', 'payment_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_payment_logs');
    }
};
