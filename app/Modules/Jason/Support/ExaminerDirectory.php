<?php

namespace App\Modules\Jason\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only access to the examiner records the nomination chain keeps.
 *
 * The finalised list CGS receives names its examiners and little else: the
 * appointment letter needs an email to reach them at, and an institution and
 * department for the address block. Those live in Hani's `examiners` table,
 * where her chain put them when the panel was chosen.
 *
 * Deliberately a thin query-builder gateway rather than her Eloquent models:
 * nothing here imports from `App\Modules\Hani`, so her models, casts and
 * relations stay hers to change. If her tables are not present -- a fresh
 * database, or a checkout where her module has not been migrated -- every
 * method returns nothing and the importer falls back to what the sheet
 * itself carries.
 *
 * Nothing in this class writes.
 */
final class ExaminerDirectory
{
    public const POOL = 'examiners';

    public const NOMINATIONS = 'examiner_nominations';

    /** The four slots a nomination fills, and the kind of letter each gets. */
    public const SLOTS = [
        'internal_main_id' => ['internal', 'main'],
        'internal_backup_id' => ['internal', 'backup'],
        'external_main_id' => ['external', 'main'],
        'external_backup_id' => ['external', 'backup'],
    ];

    public static function available(): bool
    {
        return Schema::hasTable(self::POOL);
    }

    /**
     * Whether the examiner records flag addresses that bounced.
     *
     * The nomination chain keeps the old address in `email` and records the
     * fault beside it, so reading `email` alone is not enough: a column that
     * looks perfectly good may be one the post office has already rejected.
     * The column is optional, so it is asked for before it is read. Asked
     * every time rather than remembered: a remembered answer outlives the
     * schema it describes, and a stale "no" here means quietly emailing an
     * address that was already reported as dead.
     */
    public static function flagsEmails(): bool
    {
        return self::available() && Schema::hasColumn(self::POOL, 'email_status');
    }

    /**
     * The panel a nomination names, straight from its row.
     *
     * This is the exact path: the list carries the nomination's application
     * number, and that row holds the four examiner ids. No name matching, so
     * two examiners who share a name cannot be confused for one another.
     *
     * @return array<int, array<string, string>> examiner rows, slot order
     */
    public static function panelForNomination(int|string|null $applicationId): array
    {
        if (! $applicationId || ! self::available() || ! Schema::hasTable(self::NOMINATIONS)) {
            return [];
        }

        $nomination = DB::table(self::NOMINATIONS)->where('application_id', $applicationId)->first();

        if (! $nomination) {
            return [];
        }

        $panel = [];

        foreach (self::SLOTS as $column => [$type, $role]) {
            if (! $id = ($nomination->{$column} ?? null)) {
                continue;
            }

            if ($examiner = self::byId($id)) {
                $panel[] = $examiner + ['examiner_type' => $type, 'examiner_role' => $role];
            }
        }

        return $panel;
    }

    /**
     * Everyone on the list, for a candidate to pick their examiners from.
     *
     * Grouped by kind, because the submission names one of each and a
     * single list of everybody would have to be read twice.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    public static function all(): array
    {
        if (! self::available()) {
            return ['internal' => collect(), 'external' => collect()];
        }

        $rows = DB::table(self::POOL)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department', 'faculty', 'institution', 'type']);

        return [
            'internal' => $rows->where('type', 'internal')->values(),
            'external' => $rows->where('type', 'external')->values(),
        ];
    }

    /** @return array<string, string>|null */
    public static function byId(int|string $id): ?array
    {
        if (! self::available()) {
            return null;
        }

        return self::shape(DB::table(self::POOL)->where('id', $id)->first());
    }

    /**
     * An examiner by the name the sheet calls them.
     *
     * The fallback for a list that names its examiners without carrying the
     * nomination number. Matched exactly but case- and space-insensitively,
     * because a spreadsheet picks up stray spacing; an ambiguous name
     * matches nothing rather than guessing between two people.
     *
     * @return array<string, string>|null
     */
    public static function byName(?string $name): ?array
    {
        if (! self::available() || blank($name)) {
            return null;
        }

        $needle = preg_replace('/\s+/', ' ', trim($name));

        $matches = DB::table(self::POOL)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($needle)])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? self::shape($matches->first()) : null;
    }

    /** Only the fields an appointment letter prints. */
    protected static function shape(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        $email = (string) ($row->email ?? '');

        // An address that cannot be used, and why. The flagged address is
        // kept rather than blanked: CGS needs to see what was wrong with it
        // to put it right, and the appointment itself still stands -- the
        // Dean signs the letter either way, and it can go by post.
        $problem = match (true) {
            $email === '' => 'No email address on record. CGS updates it in the Examiner Pool.',
            self::flagsEmails() && ($row->email_status ?? null) === 'invalid'
                => 'The address on record was reported as not working. CGS updates it in the Examiner Pool.',
            default => null,
        };

        return [
            // The nomination chain's own id for this examiner. Kept with
            // the letter: it is half the key the panel page asks dispatch
            // dates with, and it is how a promoted backup is told apart
            // from an examiner who already has a letter.
            'hani_examiner_id' => isset($row->id) ? (int) $row->id : null,
            'examiner_name' => (string) $row->name,
            'examiner_email' => $email,
            'email_problem' => $problem,
            'examiner_institution' => (string) ($row->institution ?: ($row->faculty ?: 'Universiti Teknologi PETRONAS')),
            'examiner_department' => (string) ($row->department ?? ''),
        ];
    }
}
