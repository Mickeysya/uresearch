<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 4 (F): the Dean may approve a different number of months than the student requested. requested_months is never touched;
 * approved_months (already there) holds the decision; these three record why, by whom and when. They are shown to approvers and
 * CGS, never to the student and never on the memo. Existing rows are left as they are: no backfill, approved_months stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('candidacy_appeals', 'dean_adjust_reason')) {
            return;
        }

        Schema::table('candidacy_appeals', function (Blueprint $table) {
            $table->text('dean_adjust_reason')->nullable();
            $table->unsignedBigInteger('dean_adjusted_by_id')->nullable();
            $table->timestamp('dean_adjusted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidacy_appeals', function (Blueprint $table) {
            $table->dropColumn(['dean_adjust_reason', 'dean_adjusted_by_id', 'dean_adjusted_at']);
        });
    }
};
