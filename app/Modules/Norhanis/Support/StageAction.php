<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;

/**
 * What an approver actually does at one stage of Travel, Publication or
 * Student Claims, as agreed with CGS at UAT (11 Nov 2026): only the last
 * approver approves. The Supervisor and the Chair of Department endorse,
 * the Non-Executive CGS reviews, and the final approver approves.
 *
 * Each workflow maps its stage keys to one of these in its ACTIONS
 * constant, the one place the mapping lives. Its Stage objects take their
 * stored past tense from that constant, so approval_history.decision and
 * every button, dialog, flash message, email, stepper and report line read
 * from the same source. Reject is the same word at every stage.
 *
 * The backing value is what approval_history.decision stores. It is
 * permanent: reword the labels below, never the values.
 */
enum StageAction: string
{
    case Endorse = 'endorsed';
    case Review = 'reviewed';
    case Approve = 'approved';

    /** The action for a stage, read from the past tense the workflow gave it. */
    public static function forStage(Stage $stage): self
    {
        return self::tryFrom($stage->decision) ?? self::Approve;
    }

    /** The action for the stage an application is waiting on, or null when it is not waiting. */
    public static function current(Application $application): ?self
    {
        $stage = $application->currentStage();

        return $stage ? self::forStage($stage) : null;
    }

    /** The button: "Endorse", "Review", "Approve". */
    public function label(): string
    {
        return match ($this) {
            self::Endorse => 'Endorse',
            self::Review => 'Review',
            self::Approve => 'Approve',
        };
    }

    /** "endorsed", "reviewed", "approved", as stored in approval_history. */
    public function past(): string
    {
        return $this->value;
    }

    /** "Endorsed", "Reviewed", "Approved". */
    public function pastLabel(): string
    {
        return ucfirst($this->value);
    }

    /** The noun: "endorsement", "review", "approval", for "Awaiting your endorsement". */
    public function noun(): string
    {
        return match ($this) {
            self::Endorse => 'endorsement',
            self::Review => 'review',
            self::Approve => 'approval',
        };
    }

    /** The decision as a heading or table cell: "Endorsed", "Rejected", "Returned". */
    public static function decisionLabel(?string $decision): string
    {
        return ($action = self::tryFrom((string) $decision))
            ? $action->pastLabel()
            : ucfirst((string) $decision);
    }
}
