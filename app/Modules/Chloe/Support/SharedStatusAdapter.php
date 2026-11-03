<?php

namespace App\Modules\Chloe\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================================================================
 *  NOT ACTIVE YET.  The shared Softbound module's table name and its student ID, submitted date and status fields are still to be
 *  obtained from the module owner (see docs/candidacy-cgs-confirmation-list.md). Until they are filled in
 *  config('candidacy.status_source_table'), this adapter finds nothing, so the reader falls through to the demo overlay.
 *  It only ever READS; we never write to a shared table.
 * ============================================================================================================================
 */
final class SharedStatusAdapter
{
    public static function isConfigured(): bool
    {
        $cfg = (array) config('candidacy.status_source_table');

        return ! empty($cfg['table']) && ! empty($cfg['student_id_column']) && Schema::hasTable($cfg['table']);
    }

    /** @return array{softbound_submitted_at:?CarbonImmutable,completed:bool,inactive:bool}|null  null = the shared source has no record for this student */
    public static function lookup(string $studentId): ?array
    {
        if (! in_array(config('candidacy.status_source'), ['shared', 'shared_with_demo_fallback'], true) || ! self::isConfigured()) {
            return null;
        }

        $cfg = (array) config('candidacy.status_source_table');
        $row = DB::table($cfg['table'])->where($cfg['student_id_column'], $studentId)->first();

        if (! $row) {
            return null;
        }

        $status = strtolower((string) ($row->{$cfg['status_column'] ?? ''} ?? ''));
        $date = ! empty($cfg['submitted_at_column']) && ! empty($row->{$cfg['submitted_at_column']}) ? CarbonImmutable::parse($row->{$cfg['submitted_at_column']}) : null;

        return [
            'softbound_submitted_at' => $date ?? (in_array($status, (array) ($cfg['submitted_values'] ?? []), true) ? CarbonImmutable::today() : null),
            'completed' => in_array($status, (array) ($cfg['completed_values'] ?? []), true),
            'inactive' => in_array($status, (array) ($cfg['inactive_values'] ?? []), true),
        ];
    }
}
