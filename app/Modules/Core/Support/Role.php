<?php

namespace App\Modules\Core\Support;

/**
 * Every role in the portal, gathered from all five module owners' role lists.
 *
 * Deliberately a PHP list backed by a VARCHAR column rather than a MySQL ENUM.
 * The legacy schema used ENUMs, and every time someone needed a new role or a
 * new decision verb they had to ALTER a table three other people also owned --
 * which is how the app ended up with three incompatible copies of schema.sql,
 * and how ga_extension_cgs.php came to write decision='reviewed' into an ENUM
 * that did not contain it. Adding a role here is a one-line change that cannot
 * break anyone else's migration.
 */
final class Role
{
    public const STUDENT = 'student';
    public const SUPERVISOR = 'supervisor';
    public const CHAIR = 'chair';
    public const NON_EXEC_CGS = 'non_exec_cgs';
    public const SENIOR_EXEC_CGS = 'senior_exec_cgs';
    public const MANAGER_CGS = 'manager_cgs';
    public const SENIOR_DIRECTOR_CGS = 'senior_director_cgs';
    public const DEAN_PGR = 'dean_pgr';
    public const ACADEMIC_EXEC = 'academic_exec';
    public const DAC = 'dac';
    public const PANEL_EXAMINER = 'panel_examiner';
    public const REGISTRY = 'registry';
    public const ADMIN = 'admin';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::STUDENT,
            self::SUPERVISOR,
            self::CHAIR,
            self::NON_EXEC_CGS,
            self::SENIOR_EXEC_CGS,
            self::MANAGER_CGS,
            self::SENIOR_DIRECTOR_CGS,
            self::DEAN_PGR,
            self::ACADEMIC_EXEC,
            self::DAC,
            self::PANEL_EXAMINER,
            self::REGISTRY,
            self::ADMIN,
        ];
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::NON_EXEC_CGS => 'Non-Executive CGS',
            self::SENIOR_EXEC_CGS => 'Senior Executive CGS',
            self::MANAGER_CGS => 'Manager CGS',
            self::SENIOR_DIRECTOR_CGS => 'Senior Director CGS',
            self::DEAN_PGR => 'Dean of PGR',
            self::ACADEMIC_EXEC => 'Academic Executive',
            self::DAC => 'DAC',
            self::PANEL_EXAMINER => 'Panel Examiner',
            default => ucwords(str_replace('_', ' ', $role)),
        };
    }
}
