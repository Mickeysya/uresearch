<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;

/**
 * What each Nureen approver row shows below its System checks: the
 * Documents section (components/documents) and the decision bar
 * (components/decision-bar) -- the primary action's label, the one-line
 * hint under it, and GA Extension's Return. One place per module, so the
 * four _detail views stay markup.
 *
 * @phpstan-type Panel array{documents: array<int, array<string, mixed>>, approveLabel: string, hint: ?string, returnAction: ?array{route: string, label: string}}
 */
final class ApproverPanel
{
    /** @return Panel */
    public static function supervision(Application $application, ?User $viewer): array
    {
        $stage = app(WorkflowEngine::class)->currentStage($application);
        $mode = app(SupervisionWorkflow::class)->modeFor($application);
        $documents = DocumentPreviews::attachments($application, $viewer);

        // At cgs_review the letter endorsing (or approving) will issue, as a
        // DRAFT -- what the old "Preview letter" button opened.
        if ($stage?->key === 'cgs_review' && $viewer?->role === Role::NON_EXEC_CGS) {
            $documents[] = DocumentPreviews::generated(
                'Appointment letter (draft preview)',
                "appointment-{$application->id}-draft.pdf",
                route('supervision.letter.preview', $application),
                route('supervision.letter.preview', [$application, 'download' => 1]),
                'DRAFT of the letter your decision issues. Viewing it issues and sends nothing.',
            );
        }

        [$label, $hint] = match (true) {
            $stage?->key === 'cgs_review' && $mode === 'endorse' => ['Endorse & issue letter',
                'Endorsing assigns the reference number, issues the letter in your name, emails it to the student (copied to the supervisor) and approves the request.'],
            $stage?->key === 'cgs_review' => ['Approve & issue letter',
                'Approving assigns the reference number, issues the letter in your name and emails it to the student (copied to the supervisor).'],
            $mode === 'automatic' && in_array($stage?->key, ['chair', 'dean_pgr'], true) => ['Approve & issue letter',
                'Your approval is final: the letter is issued at once, naming you as the approver.'],
            default => ['Approve', null],
        };

        return ['documents' => $documents, 'approveLabel' => $label, 'hint' => $hint, 'returnAction' => null];
    }

    /** @return Panel */
    public static function certification(Application $application, ?User $viewer): array
    {
        $stage = app(WorkflowEngine::class)->currentStage($application);
        $documents = DocumentPreviews::attachments($application, $viewer);
        $final = $stage?->key === 'senior_director';

        if ($final && $viewer?->role === Role::SENIOR_DIRECTOR_CGS) {
            $documents[] = DocumentPreviews::generated(
                'Certification letter (draft preview)',
                "certification-{$application->id}-draft.pdf",
                route('ga-certification.letter.preview', $application),
                route('ga-certification.letter.preview', [$application, 'download' => 1]),
                'DRAFT with your name as signatory. Viewing it issues and sends nothing.',
            );
        }

        return [
            'documents' => $documents,
            'approveLabel' => $final ? 'Approve & issue letter' : 'Approve',
            'hint' => $final ? 'Approving assigns the reference number, issues the letter in your name and emails it to the student.' : null,
            'returnAction' => null,
        ];
    }

    /** @return Panel */
    public static function gaExtension(Application $application, ?User $viewer): array
    {
        $stage = app(WorkflowEngine::class)->currentStage($application);
        $name = 'GA_Extension_Form_'.($application->student?->matric_no ?: 'application').'_'.$application->id.'.pdf';

        $documents = [
            DocumentPreviews::generated(
                'GA Extension Form (generated)',
                $name,
                route('ga-extension.form-pdf.view', $application),
                route('ga-extension.form-pdf', $application),
                'Generated from this application as it stands now, endorsements so far included.',
            ),
            ...DocumentPreviews::attachments($application, $viewer),
        ];

        // Only Non-Executive CGS at cgs_verify may send it back (see
        // GaExtensionController::returnToSupervisor()).
        $canReturn = $stage?->key === 'cgs_verify' && $viewer?->role === $stage->role;

        return [
            'documents' => $documents,
            'approveLabel' => 'Approve',
            'hint' => null,
            'returnAction' => $canReturn ? ['route' => route('ga-extension.return', $application), 'label' => 'Return to supervisor'] : null,
        ];
    }

    /** @return Panel */
    public static function attendanceAppeal(Application $application, ?User $viewer, bool $excusesSessions): array
    {
        return [
            'documents' => DocumentPreviews::attachments($application, $viewer),
            'approveLabel' => 'Approve appeal',
            'hint' => $excusesSessions ? 'Approving excuses the sessions above and recalculates the student\'s attendance and risk status at once.' : null,
            'returnAction' => null,
        ];
    }
}
