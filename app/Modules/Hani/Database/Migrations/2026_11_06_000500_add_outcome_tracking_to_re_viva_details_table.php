<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 item g: who recorded the outcome and when, and what the system
 * suggested from the two re-evaluations (null: consolidation needed), so an
 * override is visible afterwards. The outcome date is what the final-thesis
 * deadline (correction_deadline) and the next round's window run from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->unsignedTinyInteger('suggested_level')->nullable()->after('outcome_level');
            $table->timestamp('outcome_recorded_at')->nullable()->after('outcome_remarks');
            $table->foreignId('outcome_recorded_by_id')->nullable()->after('outcome_recorded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropForeign(['outcome_recorded_by_id']);
            $table->dropColumn(['suggested_level', 'outcome_recorded_at', 'outcome_recorded_by_id']);
        });
    }
};
