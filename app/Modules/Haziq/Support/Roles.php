<?php

namespace App\Modules\Haziq\Support;

/**
 * Role identifiers this module needs that Core\Support\Role does not define.
 *
 * Role is a VARCHAR, not an enum — EnsureRole and WorkflowEngine::decide()
 * both compare it as a plain string, so a role never has to be declared in
 * Core\Support\Role to work. Keeping Research Centre here instead of adding
 * it there means this module never has to touch a file it doesn't own.
 * Role::label()'s ucwords() fallback already renders this correctly as
 * "Research Centre" with no entry needed.
 */
final class Roles
{
    /** Conducts the GA interview and records Pass/Fail. Used only on the GA chain. */
    public const RESEARCH_CENTRE = 'research_centre';
}
