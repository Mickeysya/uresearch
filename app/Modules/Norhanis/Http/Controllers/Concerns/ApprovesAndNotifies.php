<?php

namespace App\Modules\Norhanis\Http\Controllers\Concerns;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Support\ApproverNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Core's approve/reject action, plus an email to whoever the application
 * moved on to. Core already emails the student; see ApproverNotifier.
 */
trait ApprovesAndNotifies
{
    use ApprovesApplications {
        decide as protected decideInCore;
    }

    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        $before = [$application->status, $application->current_stage];

        $response = $this->decideInCore($request, $application, $engine);

        // Only when the decision went through: a refused or repeated one
        // leaves the row as it was, and the next approver was told already.
        $application->refresh();

        if ([$application->status, $application->current_stage] !== $before) {
            app(ApproverNotifier::class)->arrived($application);
        }

        return $response;
    }
}
