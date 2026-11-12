<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Contracts\SuppliesReminderCount;
use App\Modules\Core\Exceptions\NotAssignedOfficer;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\CgsVerdict;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use Illuminate\Support\Collection;

/**
 * Which Non-Executive CGS officer decides each module's CGS stage
 * (config/cgs_assignment.php), and the checks that follow from it.
 *
 * WHERE IT IS ENFORCED. WorkflowEngine::decide() and returnTo() call
 * authorize() for every Non-Executive CGS stage, so every engine decision in
 * every module -- the queues, bulk decide, each module's own decide route --
 * is covered without a module changing. A module action that does not go
 * through the engine (revoking a letter, recording a Stage Gate) calls
 * authorize() or authorizeModule() itself.
 *
 * WHAT IT NEVER RESTRICTS. Viewing anything; any role but Non-Executive CGS;
 * a module that is '*' or not in the map; and a module whose officer has no
 * account here (falls back to any CGS, rather than nobody).
 *
 * Not a singleton on purpose: each instance caches the accounts it has looked
 * up, and a fresh one per use never serves an account created a moment ago
 * as missing.
 */
class CgsAssignment
{
    /** @var array<string, ?User> email => account (null when there is none) */
    protected array $accounts = [];

    public function __construct(protected ModuleRegistry $registry) {}

    /* ---------------------------------------------------------------
     | Who
     |---------------------------------------------------------------*/

    /** The assigned officer's account, or null when any CGS may decide. */
    public function officer(string $moduleKey): ?User
    {
        $entry = $this->entry($moduleKey);

        return is_array($entry) ? $this->account($entry['officer'] ?? null) : null;
    }

    /** The backup's account; null without one, or when the module is open anyway. */
    public function backup(string $moduleKey): ?User
    {
        if (! $this->officer($moduleKey)) {
            return null;
        }

        $entry = $this->entry($moduleKey);

        return $this->account($entry['backup'] ?? null);
    }

    /**
     * Who gets a module's CGS-stage emails ("awaiting you", overdue
     * reminders): the assigned officer alone, or every Non-Executive CGS
     * when the module is open.
     *
     * @return Collection<int, User>
     */
    public function recipients(string $moduleKey): Collection
    {
        $officer = $this->officer($moduleKey);

        return $officer ? collect([$officer]) : User::where('role', Role::NON_EXEC_CGS)->get();
    }

    /* ---------------------------------------------------------------
     | May they decide?
     |---------------------------------------------------------------*/

    /**
     * May $user decide $application right now, and why. Pass $stage when the
     * caller already has it (the engine does); otherwise it is looked up.
     */
    public function verdict(User $user, Application $application, ?Stage $stage = null): CgsVerdict
    {
        $stage ??= $this->currentStage($application);

        if (! $stage || $stage->role !== Role::NON_EXEC_CGS) {
            return new CgsVerdict(CgsVerdict::NOT_APPLICABLE);
        }

        $officer = $this->officer($application->module_type);

        if (! $officer) {
            return new CgsVerdict(CgsVerdict::OPEN);
        }

        $backup = $this->backup($application->module_type);
        $escalated = $this->isEscalated($application);
        $verdict = fn (string $reason) => new CgsVerdict($reason, $officer, $backup, $escalated);

        return match (true) {
            $user->is($officer) => $verdict(CgsVerdict::OFFICER),
            $escalated && $backup !== null && $user->is($backup) => $verdict(CgsVerdict::BACKUP),
            $escalated && $backup === null && $user->role === Role::NON_EXEC_CGS => $verdict(CgsVerdict::ANY_AFTER_ESCALATION),
            default => $verdict(CgsVerdict::DENIED),
        };
    }

    /** True when $user may decide $application at its current stage. */
    public function canDecide(User $user, Application $application, ?Stage $stage = null): bool
    {
        return $this->verdict($user, $application, $stage)->allowed();
    }

    /**
     * Refuse with a 403 unless $user may decide $application now. For an
     * action tied to the application's current CGS stage (a decision, Section
     * J remarks, a correction request). A no-op at any other stage.
     *
     * @throws NotAssignedOfficer
     */
    public function authorize(User $user, Application $application, ?Stage $stage = null): void
    {
        $verdict = $this->verdict($user, $application, $stage);

        if (! $verdict->allowed()) {
            throw new NotAssignedOfficer($verdict->officer);
        }
    }

    /**
     * Refuse with a 403 unless a Non-Executive CGS $user is the module's
     * officer. For a CGS decision that is not tied to a pending CGS stage --
     * revoking a letter already issued, recording a Stage Gate, confirming an
     * allowance payment -- so there is no charter clock and no escalation:
     * only the officer. Every other role passes; the route decides those.
     *
     * @throws NotAssignedOfficer
     */
    public function authorizeModule(User $user, string $moduleKey): void
    {
        if ($user->role !== Role::NON_EXEC_CGS) {
            return;
        }

        $officer = $this->officer($moduleKey);

        if ($officer && ! $user->is($officer)) {
            throw new NotAssignedOfficer($officer);
        }
    }

    /* ---------------------------------------------------------------
     | Absence cover
     |---------------------------------------------------------------*/

    /**
     * Overdue at its current stage AND reminded the configured number of
     * times there. A module that sends no counted reminders (no
     * SuppliesReminderCount) is escalated on being overdue alone.
     */
    public function isEscalated(Application $application): bool
    {
        if (! $this->registry->has($application->module_type)) {
            return false;
        }

        $module = $this->registry->get($application->module_type);

        $overdue = $module instanceof SuppliesOverdueStatus
            ? $module->decisionIsOverdue($application)
            : $this->daysAtCurrentStage($application) >= (int) config('cgs_assignment.overdue_after_days', 14);

        if (! $overdue) {
            return false;
        }

        if (! $module instanceof SuppliesReminderCount) {
            return true;
        }

        return $module->remindersAtCurrentStage($application) >= (int) config('cgs_assignment.escalate_after_reminders', 2);
    }

    /**
     * Calendar days since the application reached the stage it is on: its
     * latest approval_history row (the decision that moved it here), or its
     * submission when nobody has decided anything yet.
     */
    protected function daysAtCurrentStage(Application $application): int
    {
        $since = ApprovalHistory::where('application_id', $application->id)->max('created_at')
            ?? $application->submitted_at;

        return $since ? (int) now()->diffInDays($since, true) : 0;
    }

    /* ---------------------------------------------------------------
     | Plumbing
     |---------------------------------------------------------------*/

    /** @return string|array{officer?: string, backup?: string}|null */
    protected function entry(string $moduleKey): string|array|null
    {
        return config('cgs_assignment.modules', [])[$moduleKey] ?? null;
    }

    /** A Non-Executive CGS account by email, cached for this instance. */
    protected function account(?string $email): ?User
    {
        if (! $email) {
            return null;
        }

        $email = strtolower(trim($email));

        if (! array_key_exists($email, $this->accounts)) {
            $this->accounts[$email] = User::where('email', $email)->where('role', Role::NON_EXEC_CGS)->first();
        }

        return $this->accounts[$email];
    }

    /** The same answer as WorkflowEngine::currentStage(), without depending on the engine. */
    protected function currentStage(Application $application): ?Stage
    {
        if ($application->status !== Application::STATUS_PENDING || ! $this->registry->has($application->module_type)) {
            return null;
        }

        foreach ($this->registry->get($application->module_type)->stages($application) as $stage) {
            if ($stage->key === $application->current_stage) {
                return $stage;
            }
        }

        return null;
    }
}
