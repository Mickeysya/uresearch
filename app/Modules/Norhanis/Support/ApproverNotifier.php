<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use App\Modules\Norhanis\Notifications\ApplicationSubmitted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The emails Core's engine does not send for Travel, Claims and Publication.
 *
 * WorkflowEngine emails the student after every decision, and nobody else.
 * So an approver only learned of an application by opening their queue, and
 * a student got no word that a submission had gone through. This fills both
 * gaps from inside this module, by calling it beside submit() and decide(),
 * rather than changing Core for all six modules.
 *
 * Who is told: the student's own supervisor, the Chair of the student's
 * department, this module's own CGS officer (CgsOfficer), and everyone
 * holding the role for the other stages. Best effort, as Core's own email is: the decision is the
 * durable thing, and a mail outage must not undo or hide it.
 */
class ApproverNotifier
{
    /** The student's confirmation, then the first approver. */
    public function submitted(Application $application): void
    {
        $this->safely(fn () => $application->student?->notify(new ApplicationSubmitted($application)));

        $this->arrived($application);
    }

    /** Whoever holds the stage the application is waiting on now. */
    public function arrived(Application $application): void
    {
        $application->refresh();

        if ($application->status !== Application::STATUS_PENDING || ! $stage = $application->currentStage()) {
            return;
        }

        foreach ($this->holders($application, $stage->role) as $approver) {
            $this->safely(fn () => $approver->notify(new ApplicationAwaitingAction($application, $stage)));
        }
    }

    /** @return Collection<int, User> */
    public function holders(Application $application, string $role): Collection
    {
        $student = $application->student;

        return match ($role) {
            Role::SUPERVISOR => collect([$student?->supervisor])->filter(),
            // A student or Chair with no department on record matches nobody,
            // rather than every Chair.
            Role::CHAIR => $student?->department === null
                ? collect()
                : User::where('role', Role::CHAIR)->where('department', $student->department)->get(),
            Role::NON_EXEC_CGS => ($officer = app(CgsOfficer::class)->user())
                ? collect([$officer])
                : User::where('role', $role)->get(),
            default => User::where('role', $role)->get(),
        };
    }

    protected function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Norhanis notification failed', ['error' => $e->getMessage()]);
        }
    }
}
