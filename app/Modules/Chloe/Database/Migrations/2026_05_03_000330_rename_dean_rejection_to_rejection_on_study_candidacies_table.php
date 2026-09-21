<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarified business rule: a rejection at ANY stage (Supervisor, Chair,
 * CGS Verification or Dean) permanently blocks further appeals, not just
 * a Dean rejection -- a Return, in contrast, only reopens that same
 * application for editing and resubmission. See
 * CandidacyAppealController::applyRejection() / ::applyDeanApproval().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_candidacies', function (Blueprint $table) {
            $table->renameColumn('last_dean_rejection_at', 'last_rejection_at');
        });
    }

    public function down(): void
    {
        Schema::table('study_candidacies', function (Blueprint $table) {
            $table->renameColumn('last_rejection_at', 'last_dean_rejection_at');
        });
    }
};
