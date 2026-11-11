<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reversing the earlier "resets every month" design, confirmed
     * explicitly 2026-11-10: "ga pun sama ah, kalau ada accumulated
     * amount on service end month, month after payment accumulated
     * amount tu." GA now accumulates the same way GRA does -- a month
     * nobody confirms adds onto the next month's running total instead
     * of standing alone.
     *
     * A snapshot, not a live re-derivation, for the same reason GRA
     * keeps its own confirmed amount on allowance_payment_confirmations
     * rather than computing it after the fact: GaAllowanceLedger::
     * accumulatedTotalThrough() walks every row up to and including a
     * month and resets to zero the moment it hits a confirmed one --
     * which means re-running it for the exact month just confirmed
     * would read back as zero, not the amount that was actually paid.
     */
    public function up(): void
    {
        Schema::table('ga_allowance_payment_logs', function (Blueprint $table) {
            $table->decimal('confirmed_amount', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('ga_allowance_payment_logs', function (Blueprint $table) {
            $table->dropColumn('confirmed_amount');
        });
    }
};
