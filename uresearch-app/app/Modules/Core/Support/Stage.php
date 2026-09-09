<?php

namespace App\Modules\Core\Support;

/**
 * One step in an approval chain.
 *
 * The legacy app expressed a stage three different ways at once: a display
 * string in `current_stage` ("Chair of Department"), a snake_case key
 * ("cgs_verified"), and sometimes NULL to mean "at the first stage". Each
 * approval page then re-derived the next step with its own if/else. A Stage
 * makes the machine value, the human label, and the acting role one object,
 * so a module declares its chain once and never writes routing logic again.
 */
final class Stage
{
    public function __construct(
        /** Stored in applications.current_stage. Stable — never rename in place. */
        public readonly string $key,

        /** Shown to the student in the progress stepper. Safe to reword. */
        public readonly string $label,

        /** users.role that may act here. Enforced by the engine, not by the view. */
        public readonly string $role,

        /**
         * What a positive decision writes to approval_history.decision.
         * 'endorsed' for intermediate sign-off, 'reviewed' for a CGS check,
         * 'approved' for the final say.
         */
        public readonly string $decision = 'endorsed',

        /** Heading shown on this stage's queue screen. */
        public readonly ?string $queueTitle = null,
    ) {}

    public static function make(string $key, string $label, string $role, string $decision = 'endorsed'): self
    {
        return new self($key, $label, $role, $decision);
    }

    public function queueTitle(): string
    {
        return $this->queueTitle ?? $this->label;
    }
}
