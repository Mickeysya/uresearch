<?php

namespace App\Modules\Norhanis\Support;

/**
 * Role identifiers this module needs that Core\Support\Role does not define.
 *
 * Role is a VARCHAR, not an enum, and EnsureRole and WorkflowEngine compare
 * it as a plain string, so a role works without being declared in Core.
 * Keeping it here means Core is not touched. Haziq's Research Centre role
 * (Haziq\Support\Roles) works the same way. Role::label()'s ucwords()
 * fallback renders this one as "Lecturer".
 */
final class Roles
{
    /**
     * The course lecturer a GA/GRA works under. Approves the TA hours a
     * student logs against their course: found in UAT with PG students,
     * where the lecturer, not the supervisor, signs these off in UCampus.
     */
    public const LECTURER = 'lecturer';
}
