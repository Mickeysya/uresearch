<?php

namespace App\Modules\Norhanis\Workflows\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Support\StageAction;

/**
 * Builds a workflow's stages from its ACTIONS constant (stage key =>
 * Support\StageAction), so the word an approver acts with is declared once
 * per module and everything else reads it back: the past tense the engine
 * writes to approval_history, the queue heading, the button, the emails.
 *
 * Also Core\Contracts\DescribesApplicationProgress's "What happens next"
 * line on the student's View details page. Core's generic line says every
 * stage will "review it next"; here it says what that stage really does.
 */
trait NamesStageActions
{
    /** The action at one stage of this module. Every stage key has one. */
    public static function actionFor(string $stageKey): StageAction
    {
        return static::ACTIONS[$stageKey];
    }

    /** A Stage whose stored decision and queue heading come from ACTIONS. */
    protected function actionStage(string $key, string $label, string $role): Stage
    {
        $action = static::actionFor($key);

        return new Stage(
            key: $key,
            label: $label,
            role: $role,
            decision: $action->past(),
            queueTitle: 'Pending My '.ucfirst($action->noun()),
        );
    }

    public function describeProgress(Application $application): array
    {
        return array_filter(['next' => $this->nextStep($application)]);
    }

    /** "Chair of Department will endorse it next. ...", or null to keep Core's own line. */
    protected function nextStep(Application $application): ?string
    {
        if ($application->status !== Application::STATUS_PENDING || ! $stage = $application->currentStage()) {
            return null;
        }

        return $stage->label.' will '.strtolower(StageAction::forStage($stage)->label()).' it next.'
            .' You don\'t need to do anything right now; you will be notified when it moves.';
    }
}
