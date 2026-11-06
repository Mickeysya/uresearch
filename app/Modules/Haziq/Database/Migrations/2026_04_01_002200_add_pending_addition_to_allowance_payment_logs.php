<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reinstatement month's own row stays exactly as it already shows
     * (the resumed tertunggak/suspended total, live) when CGS adds a
     * partial-month figure to it — that figure doesn't change this
     * month's display at all, it only shows up folded into the
     * *following* month's total, same payroll-lag rule as everything
     * else CGS types in (AllowanceController::rowFor()'s `$previousStatus
     * === 'determined'` branch does the equivalent job for the entry
     * side). Confirmed explicitly 2026-11-03: "amount tu masuk next
     * monthnya."
     */
    public function up(): void
    {
        Schema::table('allowance_payment_logs', function (Blueprint $table) {
            $table->decimal('pending_addition', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('allowance_payment_logs', function (Blueprint $table) {
            $table->dropColumn('pending_addition');
        });
    }
};
