<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Academic Executive's per-item eligibility check on a Notice of Thesis
 * Submission (UAT 11 Nov 2026, matching UCampus): each checklist column
 * marked "verified" or "not_met". Per notice, so a new notice after a
 * rejection starts unmarked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->json('ae_item_checks')->nullable()->after('expected_softbound_date');
            $table->foreignId('ae_checked_by_id')->nullable()->after('ae_item_checks')->constrained('users')->nullOnDelete();
            $table->timestamp('ae_checked_at')->nullable()->after('ae_checked_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ae_checked_by_id');
            $table->dropColumn(['ae_item_checks', 'ae_checked_at']);
        });
    }
};
