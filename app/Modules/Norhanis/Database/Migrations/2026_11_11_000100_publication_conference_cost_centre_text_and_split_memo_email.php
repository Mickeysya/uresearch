<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publication changes from the UAT with CGS (11 Nov 2026):
 *
 *  - A conference no longer picks a cost centre from the list: the student
 *    types it in one field, "Cost Centre to be Utilized". It is stored in
 *    the existing `cost_centre_other` column with `cost_centre` = 'others',
 *    so reports and older rows keep reading the same two columns. A
 *    conference row filed before that rule (one of the four keys, or the
 *    free text applications held before the list existed) is moved into
 *    that shape here, keeping its words, so it shows in the new field.
 *  - `split_memo_emailed_at`: when the "CGS & Grant - Split Memo" email
 *    (memo template + CGS contact) went to the student on final approval.
 *    Set in the same statement that claims the send, so it goes once.
 */
return new class extends Migration
{
    /** Stored key => the words a migrated conference row keeps. Mirrors PublicationDetail::COST_CENTRES. */
    protected const LABELS = [
        'cgs_pay_and_claims' => 'CGS – Pay and Claims',
        'cgs_letter_of_undertaking' => 'CGS – Letter of Undertaking',
        'split_memo' => 'CGS & Grant - Split Memo',
    ];

    public function up(): void
    {
        // Guarded, so the data step below can be run again on its own (the
        // test for it does).
        if (! Schema::hasColumn('publication_details', 'split_memo_emailed_at')) {
            Schema::table('publication_details', function (Blueprint $table) {
                $table->timestamp('split_memo_emailed_at')->nullable()->after('cost_centre_other');
            });
        }

        $rows = DB::table('publication_details')
            ->where('type_of_request', 'publication_conference')
            ->where(fn ($query) => $query->where('cost_centre', '!=', 'others')->orWhereNull('cost_centre_other')->orWhere('cost_centre_other', ''))
            ->get(['id', 'cost_centre', 'cost_centre_other']);

        foreach ($rows as $row) {
            $words = $row->cost_centre === 'others'
                ? null
                : (self::LABELS[$row->cost_centre] ?? $row->cost_centre);

            DB::table('publication_details')->where('id', $row->id)->update([
                'cost_centre' => 'others',
                // Already "others" with no words: nothing to carry, so say so
                // rather than leave the field blank.
                'cost_centre_other' => mb_substr((string) ($row->cost_centre_other ?: ($words ?: 'Not stated')), 0, 150),
            ]);
        }
    }

    /**
     * Drops the column only. The conference rows keep their new shape: the
     * text they held is still in cost_centre_other, and a conference could
     * only use "Others" before this migration too.
     */
    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn('split_memo_emailed_at');
        });
    }
};
