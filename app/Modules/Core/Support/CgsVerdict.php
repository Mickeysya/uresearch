<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;

/**
 * CgsAssignment's answer to "may this user decide this application now, and
 * why?" -- enough for the engine to refuse and for a queue row to explain.
 */
final class CgsVerdict
{
    /** Not a Non-Executive CGS stage: the assignment map has no say. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** The module is '*', unlisted, or its officer has no account: any CGS. */
    public const OPEN = 'open';

    /** The viewer is the assigned officer. */
    public const OFFICER = 'officer';

    /** Escalated, and the viewer is the configured backup. */
    public const BACKUP = 'backup';

    /** Escalated with no backup set: any Non-Executive CGS may decide. */
    public const ANY_AFTER_ESCALATION = 'any_after_escalation';

    /** Somebody else's to decide. */
    public const DENIED = 'denied';

    public function __construct(
        public readonly string $reason,
        public readonly ?User $officer = null,
        public readonly ?User $backup = null,
        public readonly bool $escalated = false,
    ) {}

    public function allowed(): bool
    {
        return $this->reason !== self::DENIED;
    }

    /** True when the module has one assigned officer (so a row names them). */
    public function restricted(): bool
    {
        return $this->officer !== null;
    }

    /** "Assigned to: ..." text, or null for a stage the map does not cover. */
    public function assigneeName(): ?string
    {
        return match (true) {
            $this->reason === self::NOT_APPLICABLE => null,
            $this->officer !== null => $this->officer->name,
            default => 'Any CGS officer',
        };
    }

    /** Why someone besides the officer may act, or null when nobody else may. */
    public function coverNote(): ?string
    {
        if (! $this->restricted() || ! $this->escalated) {
            return null;
        }

        return $this->backup
            ? 'Overdue: backup may decide'
            : 'Overdue: any CGS officer may decide';
    }
}
