<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appeal Hardbound Submission was built as an appeal against a rejected
 * submission, which is what docs/scope/jason.md §3 describes. CGS means
 * something else by it: a candidate who cannot submit the hardbound thesis
 * by their deadline asks for an extension, on a memo the supervisor endorses.
 *
 * So the table stops pointing at a rejected submission -- an extension is
 * asked for before submitting, not after being turned down -- and starts
 * holding what the memo says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardbound_appeal_details', function (Blueprint $table) {
            // Why the candidate cannot meet the deadline. Printed on the memo.
            $table->text('reason')->nullable()->after('application_id');

            // The deadline they are working to, and the one they are asking
            // for. Both are on the memo, so both are the candidate's own
            // statement rather than a date the portal holds elsewhere: no
            // module owns a candidate's submission deadline yet.
            $table->date('original_deadline')->nullable()->after('reason');
            $table->date('requested_until')->nullable()->after('original_deadline');
        });

        // Whatever justification text exists carries over as the reason: it
        // is the same field under the old meaning.
        if (Schema::hasColumn('hardbound_appeal_details', 'justification')) {
            \Illuminate\Support\Facades\DB::table('hardbound_appeal_details')
                ->whereNull('reason')
                ->update(['reason' => \Illuminate\Support\Facades\DB::raw('justification')]);
        }

        Schema::table('hardbound_appeal_details', function (Blueprint $table) {
            // The appeal no longer belongs to a hardbound submission, and the
            // Dean PFR report no longer exists -- the ruling stage went with
            // it, because CGS emails the outcome personally.
            $table->dropConstrainedForeignId('hardbound_application_id');
            $table->dropColumn(['justification', 'pfr_recommendation']);
        });
    }

    public function down(): void
    {
        Schema::table('hardbound_appeal_details', function (Blueprint $table) {
            $table->foreignId('hardbound_application_id')->nullable()
                ->constrained('applications')->cascadeOnDelete();
            $table->text('justification')->nullable();
            $table->text('pfr_recommendation')->nullable();
            $table->dropColumn(['reason', 'original_deadline', 'requested_until']);
        });
    }
};
