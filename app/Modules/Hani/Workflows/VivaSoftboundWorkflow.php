<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\VivaSoftboundDetail;

/**
 * Softbound thesis submission (CGS Online Form #019), step 6 of the MSc viva
 * flow.
 *
 * Its own application rather than a stage on the notice, deliberately. The
 * notice cannot wait on a stage for the STUDENT to upload something: the
 * queue's bulk approve posts straight to WorkflowEngine::decide(), which has
 * no hook for "not yet", so an AE could tick it through with nothing
 * uploaded. As its own application it only exists, and only reaches the AE,
 * once the student has filed it.
 *
 * Reject means "returned to the student": that attempt closes, the notice
 * stays open, and the student files a fresh attempt. See
 * VivaSoftboundController::decide().
 */
class VivaSoftboundWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'viva_softbound';
    }

    public function label(): string
    {
        return 'Softbound Thesis';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'softbound_check',
                label: 'Academic Executive',
                role: Role::ACADEMIC_EXEC,
                decision: 'verified',
                queueTitle: 'Theses to Check',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = VivaSoftboundDetail::where('application_id', $application->id)->first();

        return $detail
            ? 'Form #019: similarity '.rtrim(rtrim($detail->similarity_percent, '0'), '.').'%, '.number_format($detail->word_count).' words'
            : 'Softbound thesis (Form #019)';
    }

    public function createRoute(): ?string
    {
        return 'viva.softbound.create';
    }

    public function queueRoute(): string
    {
        return 'viva.softbound.queue';
    }
}
