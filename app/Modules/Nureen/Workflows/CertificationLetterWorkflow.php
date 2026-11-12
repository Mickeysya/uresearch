<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\Defaults\DescribesApplicationDetailsDefaults;
use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Contracts\SuppliesReminderCount;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Support\ApplicationDetailsContent;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Support\TrackingDescriptions;
use App\Modules\Nureen\Workflows\Concerns\ExpectsDecisionByCharter;

/**
 * GA/GRA Certification Letter.
 *
 * Three stages: the student's own named supervisor reviews first, then CGS
 * Staff verify the appointment type, then Senior Director CGS gives the
 * final digital endorsement. The 'cgs_verify' / 'senior_director' keys are
 * unchanged from before this module had a supervisor stage at all -- an
 * application already sitting at either one (submitted before this change)
 * is found and decided exactly the same way, since current_stage is looked
 * up by key, not by position in the chain. See CertificationController::
 * decide(), which addresses the new stage to one named person the same way
 * SupervisionController does, not to everyone holding the Supervisor role.
 * The Senior Director's approval is still what triggers PDF generation.
 */
class CertificationLetterWorkflow implements DecidesOneAtATime, DescribesApplicationDetails, DescribesApplicationProgress, SuppliesDecisionDates, SuppliesOverdueStatus, SuppliesReminderCount, WorkflowModule
{
    /** The student's details page -- Core\Contracts\DescribesApplicationDetails; see Support\ApplicationDetailsContent. */
    use DescribesApplicationDetailsDefaults;

    public function details(Application $application): array
    {
        return app(ApplicationDetailsContent::class)->certification($application);
    }

    public function timelineEvents(Application $application): array
    {
        return [
            ...app(ApplicationDetailsContent::class)->reminders($application),
            ...app(ApplicationDetailsContent::class)->letterEvents($this->issuedDocument($application)),
        ];
    }

    public function stageTargetDays(Application $application, string $stageKey): ?int
    {
        return app(ApplicationDetailsContent::class)->target($application, $stageKey);
    }

    public function waitingFor(Application $application): ?string
    {
        return app(ApplicationDetailsContent::class)->waitingFor($application);
    }

    public function issuedDocument(Application $application): ?array
    {
        return app(ApplicationDetailsContent::class)->certificationLetter($application);
    }

    /** Track My Applications tab (config/tracking.php) and card icon. */
    public const CATEGORY = 'assistantships';

    public const ICON = 'certificate';

    /** Track My Applications: Core\Contracts\DescribesApplicationProgress -- see Support\TrackingDescriptions. */
    public function describeProgress(Application $application): array
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();

        return app(TrackingDescriptions::class)->letter($application, LetterRenderer::CERTIFICATION_DOC_TYPE, $detail?->reference_number, $detail?->revoked_at);
    }

    /** expectedDecisionDate(): the Client Charter date, for the student dashboard. */
    use ExpectsDecisionByCharter;

    public function key(): string
    {
        return 'ga_certification';
    }

    public function label(): string
    {
        return 'Certification Letter';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
            new Stage(
                key: 'cgs_verify',
                label: 'CGS Staff',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Pending My Verification',
            ),
            new Stage(
                key: 'senior_director',
                label: 'Senior Director CGS',
                role: Role::SENIOR_DIRECTOR_CGS,
                decision: 'approved',
                queueTitle: 'Final Endorsement',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA/GRA certification request';
        }

        // A saved draft may not have these yet (they are nullable for drafts).
        $type = $detail->appointment_type ? $detail->appointment_type.' certification' : 'GA/GRA certification request';

        return $detail->period_start && $detail->period_end
            ? $type.', '.$detail->period_start->format('M Y').' – '.$detail->period_end->format('M Y')
            : $type;
    }

    public function createRoute(): ?string
    {
        return 'ga-certification.create';
    }

    public function queueRoute(): string
    {
        return 'ga-certification.queue';
    }
}
