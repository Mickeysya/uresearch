<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use Carbon\CarbonInterface;

/**
 * This module's part of the student's application details page
 * (Core\Contracts\DescribesApplicationDetails, implemented on the four
 * workflows).
 *
 * Shows the student only what they submitted, and what happened to it.
 * Never the appointment check result, CGS's revocation note, or any
 * reviewer's name on a reminder.
 */
class ApplicationDetailsContent
{
    /* -----------------------------------------------------------------
     | "Your application"
     |------------------------------------------------------------------*/

    /** @return array<string, string> */
    public function gaExtension(Application $application): array
    {
        $d = GaExtensionDetail::where('application_id', $application->id)->first();

        if (! $d) {
            return [];
        }

        $rows = [
            'Current end date' => $d->current_end_date?->format('j M Y'),
            'Requested new end date' => $d->requested_new_end_date?->format('j M Y'),
            'Reason for extension' => $d->reason_for_extension,
        ];

        foreach ((array) config('nureen.ga_extension.additional_fields', []) as $key => $field) {
            $rows[$field['label']] = $d->additional_info[$key] ?? null;
        }

        return $this->filled($rows);
    }

    /** @return array<string, string> */
    public function certification(Application $application): array
    {
        $d = GaCertificationDetail::where('application_id', $application->id)->first();

        if (! $d) {
            return [];
        }

        $rows = [
            'Appointment type' => $d->appointment_type === 'GRA' ? 'Graduate Research Assistant (GRA)' : 'Graduate Assistant (GA)',
            'Appointment period' => $d->period_start?->format('j M Y').' – '.$d->period_end?->format('j M Y'),
            'Purpose' => $d->purpose,
        ];

        // GA: the course fields; GRA: the project fields -- the same
        // per-type list the form and the letter use. The appointment
        // check result (AppointmentVerifier) is CGS's, never shown here.
        foreach ((array) config("nureen.ga_certification.fields.{$d->appointment_type}", []) as $key => $field) {
            $rows[$field['label']] = $d->{$key};
        }

        return $this->filled($rows);
    }

    /** @return array<string, string> */
    public function supervision(Application $application): array
    {
        $d = SupervisionDetail::with('requestedSupervisor')->where('application_id', $application->id)->first();

        if (! $d) {
            return [];
        }

        return $this->filled([
            'Requested supervisor' => $d->requestedSupervisor?->name,
            'Appointment role' => match ($d->appointment_role) {
                SupervisionDetail::ROLE_MAIN => 'Main Supervisor',
                SupervisionDetail::ROLE_CO => 'Co-Supervisor',
                default => null,
            },
            'Appointment type' => match ($d->appointment_type) {
                'new' => 'New',
                'existing' => 'Existing',
                default => $d->appointment_type,
            },
            'Justification' => $d->justification,
        ]);
    }

    /** @return array<string, string> */
    public function attendanceAppeal(Application $application): array
    {
        $d = AttendanceAppealDetail::with('attendanceRecord')->where('application_id', $application->id)->first();

        if (! $d) {
            return [];
        }

        return $this->filled([
            'Period' => $d->attendanceRecord ? 'Period ending '.$d->attendanceRecord->period_end->format('j M Y') : null,
            'Sessions to excuse' => $d->sessions_requested !== null ? (string) $d->sessions_requested : null,
            'Sessions approved' => $d->sessions_approved !== null ? (string) $d->sessions_approved : null,
            'Reason' => $d->reason,
        ]);
    }

    /* -----------------------------------------------------------------
     | Timeline: reminders, letter issued / revoked
     |------------------------------------------------------------------*/

    /** @return array<int, array<string, mixed>> */
    public function reminders(Application $application): array
    {
        if ($application->module_type === 'supervision') {
            $d = SupervisionDetail::where('application_id', $application->id)->first();
            $at = $d?->reminded_at;
            $count = (int) ($d?->reminder_count ?? 0);
            $stageKey = $d?->reminded_stage_key;
        } else {
            $r = ApplicationReminder::where('application_id', $application->id)->first();
            $at = $r?->last_reminded_at;
            $count = (int) ($r?->reminder_count ?? 0);
            $stageKey = $r?->reminded_stage_key;
        }

        if (! $at) {
            return [];
        }

        // The role, never a person: "CGS Staff reminded".
        $stage = collect(app(WorkflowEngine::class)->stagesFor($application))->firstWhere('key', $stageKey);

        return [[
            'date' => $at,
            'title' => ($stage?->label ?? 'The reviewer').' reminded',
            'detail' => $count > 1 ? "{$count} reminders sent for this step; the latest is shown." : 'An automatic reminder was sent because this step passed its target time.',
            'tone' => 'warn',
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    public function letterEvents(?array $issued): array
    {
        if (empty($issued['document'])) {
            return [];
        }

        $events = [[
            'date' => $issued['issued_at'] ?? $issued['document']->created_at,
            'title' => 'Letter issued',
            'detail' => 'Reference '.$issued['reference'],
            'tone' => 'good',
        ]];

        if (! empty($issued['revoked_at'])) {
            $events[] = [
                'date' => $issued['revoked_at'],
                'title' => 'Letter revoked',
                'quote' => $issued['revoked_reason'] ?? null,
                'tone' => 'critical',
            ];
        }

        return $events;
    }

    /* -----------------------------------------------------------------
     | Documents: the issued letter
     |------------------------------------------------------------------*/

    /** @return array<string, mixed> */
    public function appointmentLetter(Application $application): array
    {
        $d = SupervisionDetail::where('application_id', $application->id)->first();

        return $this->letter($application, 'Supervisor appointment letter', LetterRenderer::APPOINTMENT_DOC_TYPE,
            $d?->letter_reference, 'appointment.verify', $d?->letter_issued_at, $d?->letter_revoked_at, $d?->letter_revoked_reason);
    }

    /** @return array<string, mixed> */
    public function certificationLetter(Application $application): array
    {
        $d = GaCertificationDetail::where('application_id', $application->id)->first();

        return $this->letter($application, 'GA/GRA certification letter', LetterRenderer::CERTIFICATION_DOC_TYPE,
            $d?->reference_number, 'certificate.verify', null, $d?->revoked_at, $d?->revoked_reason);
    }

    /** The charter's working days for a stage, or null when CGS gives none. */
    public function target(Application $application, string $stageKey): ?int
    {
        return app(ClientCharter::class)->charterDays($application->module_type, $stageKey);
    }

    /** @return array<string, mixed> */
    protected function letter(Application $application, string $label, string $docType, ?string $reference, string $verifyRoute, ?CarbonInterface $issuedAt, ?CarbonInterface $revokedAt, ?string $revokedReason): array
    {
        $document = $reference
            ? ($application->relationLoaded('documents') ? $application->documents : $application->documents()->get())
                ->where('doc_type', $docType)->sortByDesc('id')->first()
            : null;

        if (! $document) {
            return ['label' => $label];
        }

        return array_filter([
            'label' => $label,
            'reference' => $reference,
            'document' => $document,
            'verify_url' => route($verifyRoute, $reference),
            'issued_at' => $issuedAt,
            'revoked_at' => $revokedAt,
            // The reason CGS gave, shown to the student whose letter it is.
            // CGS's separate internal note (letter_revoke_note) never is.
            'revoked_reason' => $revokedReason,
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string, ?string>  $rows */
    protected function filled(array $rows): array
    {
        return array_filter($rows, fn ($v) => $v !== null && trim((string) $v) !== '');
    }
}
