<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One row in, one attendance_records upsert out -- the rule set every way a
 * row can arrive shares: known student, a real date, sane counts, never a
 * duplicate period.
 *
 * Extracted from AttendanceController::upload(), which used to be the only
 * caller and had this inline. Behaviour for that path is unchanged; the
 * manual upload still builds its own "Processed N records..." message from
 * the IngestSummary this returns. attendance:sync (and, through it, every
 * AttendanceSource) is the other caller now.
 */
class AttendanceIngestor
{
    /**
     * @param  Collection<int, array{matric_no: mixed, period_end: mixed, sessions_attended: mixed, sessions_total: mixed, line?: int|string}>  $rows
     */
    public function ingest(Collection $rows): IngestSummary
    {
        $read = $rows->count();
        $added = 0;
        $updated = 0;
        $problems = [];
        $newlyAtRisk = [];

        DB::transaction(function () use ($rows, &$added, &$updated, &$problems, &$newlyAtRisk) {
            foreach ($rows as $row) {
                $label = isset($row['line']) ? "Row {$row['line']}" : 'Row';

                $matricNo = trim((string) ($row['matric_no'] ?? ''));
                $periodEndRaw = $row['period_end'] ?? null;

                // A wholly blank row is trailing whitespace, not a mistake.
                if ($matricNo === '' && ($periodEndRaw === null || trim((string) $periodEndRaw) === '')) {
                    continue;
                }

                // An .xlsx date cell, an Excel serial, or a string in any of
                // the formats Excel writes -- all normalised to Y-m-d here,
                // the same as the manual upload always has been.
                $periodEnd = AttendanceSheet::parseDate($periodEndRaw);

                $student = User::where('matric_no', $matricNo)
                    ->where('role', Role::STUDENT)
                    ->first();

                if (! $student) {
                    $problems[] = "{$label}: no student with matric number \"{$matricNo}\".";

                    continue;
                }

                if ($periodEnd === null) {
                    $problems[] = "{$label}: period_end is not a date the importer recognises (use YYYY-MM-DD).";

                    continue;
                }

                // A period is recorded once it has ended, never before: a
                // future period_end would count sessions that have not
                // happened yet, from any source or upload.
                if ($periodEnd > now()->toDateString()) {
                    $problems[] = "{$label}: period_end {$periodEnd} is in the future; a period can only be recorded once it has ended.";

                    continue;
                }

                $attended = $row['sessions_attended'] ?? null;
                $total = $row['sessions_total'] ?? null;

                if (! is_numeric($attended) || ! is_numeric($total)) {
                    $problems[] = "{$label}: sessions_attended and sessions_total must both be numbers.";

                    continue;
                }

                if ((int) $total <= 0) {
                    $problems[] = "{$label}: sessions_total must be greater than zero.";

                    continue;
                }

                if ((int) $attended > (int) $total) {
                    $problems[] = "{$label}: sessions_attended ({$attended}) is greater than sessions_total ({$total}).";

                    continue;
                }

                // Whether this period already exists decides added vs
                // updated for the summary -- recordPeriod() itself does not
                // distinguish, it just upserts.
                $existed = AttendanceRecord::where('student_id', $student->id)
                    ->whereDate('period_end', $periodEnd)
                    ->exists();

                $wasAtRisk = AttendanceRecord::where('student_id', $student->id)
                    ->whereDate('period_end', '<', $periodEnd)
                    ->orderByDesc('period_end')
                    ->value('at_risk') ?? false;

                // Replaces the period if it is already on file -- see the
                // note on recordPeriod() for why the lookup there cannot be
                // a plain equality on the date string.
                $record = AttendanceRecord::recordPeriod(
                    $student->id,
                    $periodEnd,
                    (int) $attended,
                    (int) $total,
                );

                $existed ? $updated++ : $added++;

                $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);

                if ($record->at_risk && ! $wasAtRisk) {
                    $newlyAtRisk[] = $record;
                }
            }
        });

        // No email from here any more: the early warning is sent by
        // Support\AttendanceEarlyWarning, which the callers (the sync runner
        // and the manual upload) run after this -- it also applies the
        // end-of-semester projection and the no-repeat rule.
        // `newlyAtRisk` still counts the original rule's new flags.

        return new IngestSummary(
            read: $read,
            added: $added,
            updated: $updated,
            rejected: count($problems),
            newlyAtRisk: count($newlyAtRisk),
            reasons: $problems,
        );
    }
}
