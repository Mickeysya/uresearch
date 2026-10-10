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

        return [
            'examiner_name' => (string) $row->name,
            'examiner_email' => (string) ($row->email ?? ''),
            'examiner_institution' => (string) ($row->institution ?: ($row->faculty ?: 'Universiti Teknologi PETRONAS')),
            'examiner_department' => (string) ($row->department ?? ''),
        ];
    }
}
