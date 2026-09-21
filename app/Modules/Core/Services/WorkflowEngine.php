<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Notifications\ApplicationReturned;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        // The role check above is not enough for Chair and Academic
        // Executive: it proves the actor holds the stage's role, not that
        // this is their department's row. Without this, one Chair could
        // decide another department's application straight off the URL,
        // and queue()'s own filtering (ApprovesApplications::queueFor())
        // never runs to stop them, since it only shapes what the queue
        // *page* shows. See Role::isDepartmentScoped().
        if (Role::isDepartmentScoped($actor->role) && $actor->department !== $application->student?->department) {
            throw new UnauthorizedException(
                "'{$actor->department}' cannot act on a '{$application->student?->department}' application."
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

            activity('workflow')
                ->causedBy($actor)
                ->performedOn($application)
                ->withProperties([
                    'action' => $approved ? $stage->decision : 'rejected',
                    'module' => $application->module_type,
                    'stage' => $stage->key,
                    'stage_label' => $stage->label,
                    'status' => $application->status,
                ])
                ->log($approved ? 'Application '.$stage->decision : 'Application rejected');

            // AFTER THE COMMIT, never inside it. See notifyStudent().
            DB::afterCommit(fn () => $this->notifyStudent($application, $stage, $approved));

            return $application;
        });
    }

    /**
     * Send an application BACK to an earlier stage instead of ending it.
     *
     * A rejection is final: decide('reject') marks the application rejected
     * and the chain stops. That is right for "no", and wrong for "this list
     * has a problem, the department needs to pick again" -- which is exactly
     * what the Senior Director and the Dean do to an examiner nomination
     * they will not sign off. Rejecting there would kill the candidate's
     * nomination outright and split the audit trail across two applications.
     *
     * So: the decision is recorded as 'returned' at the stage that returned
     * it, current_stage moves BACK, and the application stays pending. The
     * chain then replays forward through every stage in between, which is
     * the point -- a list the Dean sent back is re-approved by the Academic
     * Executive and re-compiled by CGS before it reaches the Dean again.
     *
     * Remarks are mandatory here, unlike on approve/reject. Somebody is
     * being asked to redo work; they are owed the reason.
     *
     * Forward jumps are refused. This method exists to undo progress, not to
     * skip a stage, and letting it move an application forward would be a
     * way around every authorisation check on the stages in between.
     */
    public function returnTo(
        Application $application,
        User $actor,
        string $stageKey,
        string $remarks,
    ): Application {
        $stage = $this->currentStage($application);

        if ($stage === null) {
            throw new \LogicException("Application #{$application->id} is not awaiting a decision.");
        }

        // Same two checks decide() makes, for the same reasons: holding the
        // role is not the same as this being your department's row.
        if ($actor->role !== $stage->role) {
            throw new UnauthorizedException(
                "Role '{$actor->role}' cannot act at the '{$stage->label}' stage."
            );
        }

        if (Role::isDepartmentScoped($actor->role) && $actor->department !== $application->student?->department) {
            throw new UnauthorizedException(
                "'{$actor->department}' cannot act on a '{$application->student?->department}' application."
            );
        }

        $stages = $this->stagesFor($application);
        $keys = array_map(fn (Stage $s) => $s->key, $stages);

        $targetIndex = array_search($stageKey, $keys, true);
        $currentIndex = array_search($stage->key, $keys, true);

        if ($targetIndex === false) {
            throw new \LogicException("Stage '{$stageKey}' is not on this application's chain.");
        }

        if ($targetIndex >= $currentIndex) {
            throw new \LogicException(
                "'{$stageKey}' is not earlier than '{$stage->key}'. returnTo() only moves an application back."
            );
        }

        $target = $stages[$targetIndex];

        return DB::transaction(function () use ($application, $actor, $stage, $target, $remarks) {
            ApprovalHistory::create([
                'application_id' => $application->id,
                'approver_id' => $actor->id,
                'stage_key' => $stage->key,
                'stage_label' => $stage->label,
                'decision' => 'returned',
                'remarks' => $remarks,
            ]);

            // Still pending -- that is the whole difference from a rejection.
            $application->forceFill([
                'status' => Application::STATUS_PENDING,
                'current_stage' => $target->key,
            ])->save();

            $application->refresh();

            activity('workflow')
                ->causedBy($actor)
                ->performedOn($application)
                ->withProperties([
                    'action' => 'returned',
                    'module' => $application->module_type,
                    'stage' => $stage->key,
                    'stage_label' => $stage->label,
                    'returned_to' => $target->key,
                    'status' => $application->status,
                ])
                ->log('Application returned to '.$target->label);

            // AFTER THE COMMIT, never inside it -- see notifyStudent().
            DB::afterCommit(function () use ($application, $stage, $target, $remarks) {
                $this->notifyReturn($application, $stage, $target, $remarks);
            });

            return $application;
        });
    }

    /**
     * A return has two audiences, unlike every other decision.
     *
     * The student is told, as always. So is whoever now has to act: the work
     * has gone backwards to a desk that had already cleared it, and nothing
     * would otherwise tell them except the row quietly reappearing in their
     * queue. A department-scoped stage is narrowed to that application's own
     * department -- see Role::isDepartmentScoped() -- so eleven Academic
     * Executives are not emailed about one department's list.
     *
     * Best effort, for the reason notifyStudent() gives: the decision is
     * committed and durable; the announcement is not worth a 500 over.
     */
    protected function notifyReturn(Application $application, Stage $from, Stage $target, string $remarks): void
    {
        try {
            $notification = new ApplicationReturned($application, $from, $target, $remarks);

            $application->student?->notify($notification);

            $owners = User::where('role', $target->role)
                ->when(
                    Role::isDepartmentScoped($target->role) && $application->student?->department,
                    fn ($q) => $q->where('department', $application->student->department)
                )
                ->get()
                ->reject(fn (User $user) => $user->is($application->student));

            Notification::send($owners, $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Tell the student what happened. Deliberately outside the transaction.
     *
     * TWO FAILURES THIS AVOIDS, and they point in opposite directions.
     *
     * Notifying INSIDE the transaction meant a queue broker that was down
     * threw, the transaction rolled back, and a perfectly valid approval was
     * lost -- an outage in the email path destroying the decision it was
     * only meant to announce. That is what turned a config mistake into data
     * loss once already.
     *
     * The other direction is quieter and worse. Three controllers wrap
     * decide() in a transaction of their own (RpdAppeal, RpdDismissal,
     * AppointmentLetter), so a notification dispatched inside it goes out
     * while that outer transaction is still open -- and if it later rolls
     * back, the student has been told about an approval that never happened,
     * with nothing in the database to show for it.
     *
     * DB::afterCommit() answers both: it defers to the OUTERMOST commit, and
     * runs immediately when there is no transaction at all.
     *
     * The try/catch is the other half. Once the decision is committed it is
     * the durable thing and the email is best effort, so a broker outage is
     * reported and swallowed rather than turned into a 500 on a request that
     * actually succeeded. An approver should not be shown an error for work
     * the system accepted.
     */
    protected function notifyStudent(Application $application, Stage $stage, bool $approved): void
    {
        try {
            $application->student?->notify(new ApplicationDecided($application, $stage, $approved));
        } catch (\Throwable $e) {
            report($e);
        }
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
