<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Support\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Moves applications along their chain. This is the single place in the
 * codebase that writes applications.status / current_stage.
 *
 * It replaces the fourteen near-identical approval pages of the legacy app,
 * each of which re-implemented "log the decision, work out the next stage,
 * update the row, email the student" -- and each of which drifted.
 */
class WorkflowEngine
{
    public function __construct(protected ModuleRegistry $registry) {}

    /**
     * Put a freshly created application onto the first stage of its chain.
     * Call this from your form controller after saving your detail row.
     */
    public function submit(Application $application): Application
    {
        $stages = $this->stagesFor($application);

        if ($stages === []) {
            throw new \LogicException(
                "Module '{$application->module_type}' declared an empty stage chain."
            );
        }

        $application->forceFill([
            'status' => Application::STATUS_PENDING,
            'current_stage' => $stages[0]->key,
            'submitted_at' => now(),
        ])->save();

        return $application;
    }

    /**
     * Record a decision and advance (or terminate) the application.
     *
     * @param  string  $decision  'approve' or 'reject' — the caller states intent;
     *                            the verb written to history comes from the Stage.
     */
    public function decide(
        Application $application,
        User $actor,
        string $decision,
        ?string $remarks = null
    ): Application {
        $stage = $this->currentStage($application);

        if ($stage === null) {
            throw new \LogicException("Application #{$application->id} is not awaiting a decision.");
        }

        // Authorisation lives here, not in the view. A student cannot advance
        // their own application by visiting the Dean's URL directly.
        if ($actor->role !== $stage->role) {
            throw new UnauthorizedException(
                "Role '{$actor->role}' cannot act at the '{$stage->label}' stage."
            );
        }

        $approved = $decision === 'approve';

        return DB::transaction(function () use ($application, $actor, $stage, $approved, $remarks) {
            ApprovalHistory::create([
                'application_id' => $application->id,
                'approver_id' => $actor->id,
                'stage_key' => $stage->key,
                'stage_label' => $stage->label,
                'decision' => $approved ? $stage->decision : 'rejected',
                'remarks' => $remarks,
            ]);

            if (! $approved) {
                // Keep current_stage pointing at the rejecting stage so the
                // stepper can show the student exactly where it stopped.
                $application->forceFill(['status' => Application::STATUS_REJECTED])->save();
            } else {
                $next = $this->nextStage($application, $stage);

                $application->forceFill($next
                    ? ['current_stage' => $next->key]
                    : ['status' => Application::STATUS_APPROVED]
                )->save();
            }

            $application->refresh();
            $application->student?->notify(new ApplicationDecided($application, $stage, $approved));

            return $application;
        });
    }

    /**
     * The queue for one stage: every application of that module sitting on
     * that stage, still open. Replaces every hand-written queue query.
     */
    public function queue(string $moduleKey, string $stageKey): Builder
    {
        return Application::query()
            ->where('module_type', $moduleKey)
            ->where('current_stage', $stageKey)
            ->where('status', Application::STATUS_PENDING)
            ->with('student')
            ->orderBy('submitted_at');
    }

    /** @return array<int, Stage> */
    public function stagesFor(Application $application): array
    {
        return $this->registry->get($application->module_type)->stages($application);
    }

    public function currentStage(Application $application): ?Stage
    {
        if ($application->status !== Application::STATUS_PENDING) {
            return null;
        }

        foreach ($this->stagesFor($application) as $stage) {
            if ($stage->key === $application->current_stage) {
                return $stage;
            }
        }

        return null;
    }

    protected function nextStage(Application $application, Stage $current): ?Stage
    {
        // Re-resolve the chain rather than caching it: a decision may have
        // changed the data the chain depends on.
        $stages = $this->stagesFor($application);

        foreach ($stages as $i => $stage) {
            if ($stage->key === $current->key) {
                return $stages[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Everything the stepper needs, computed in one place.
     *
     * @return array<int, array{stage: Stage, state: string}>
     *         state is one of: completed, current, rejected, upcoming
     */
    public function progress(Application $application): array
    {
        $stages = $this->stagesFor($application);
        $currentIndex = null;

        foreach ($stages as $i => $stage) {
            if ($stage->key === $application->current_stage) {
                $currentIndex = $i;
                break;
            }
        }

        $isApproved = $application->status === Application::STATUS_APPROVED;
        $isRejected = $application->status === Application::STATUS_REJECTED;

        // An approved application has cleared the whole chain.
        if ($isApproved) {
            $currentIndex = count($stages);
        }

        $currentIndex ??= 0;

        $out = [];

        foreach ($stages as $i => $stage) {
            $state = match (true) {
                $isRejected && $i === $currentIndex => 'rejected',
                $isRejected && $i > $currentIndex => 'upcoming',
                $i < $currentIndex => 'completed',
                $i === $currentIndex => 'current',
                default => 'upcoming',
            };

            $out[] = ['stage' => $stage, 'state' => $state];
        }

        return $out;
    }
}
