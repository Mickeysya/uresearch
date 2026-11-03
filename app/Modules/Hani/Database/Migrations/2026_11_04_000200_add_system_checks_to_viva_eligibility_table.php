<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the system found for each eligibility item when the notice was filed
 * (Support\Eligibility): {column: {status, message}}. A snapshot, so the
 * Academic Executive confirms what the student filed on, not today's lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_eligibility', function (Blueprint $table) {
            $table->json('system_checks')->nullable()->after('within_candidacy_period');
            $table->timestamp('system_checked_at')->nullable()->after('system_checks');
        });
    }

    public function down(): void
    {
        Schema::table('viva_eligibility', function (Blueprint $table) {
            $table->dropColumn(['system_checks', 'system_checked_at']);
        });
    }
};
