<?php

namespace Tests\Feature\Nureen\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use Illuminate\Http\UploadedFile;

/**
 * A complete GA Extension submission: the official form's Section B-D
 * fields (config('nureen.ga_extension.applicant_fields')) and one fake PDF
 * per required document type (config('nureen.ga_extension.required_documents')).
 *
 * Dates are relative to now() so the payload never becomes a backdated
 * appointment, which GaExtensionController::store() refuses: the current
 * appointment ends at the end of next month, so the extension starts on a
 * 1st and runs six whole months.
 */
trait FilesGaExtensions
{
    /**
     * Since 2026-11-07 a student may only apply once their GA application is
     * approved, and the extension's current end date is that application's
     * service end date, never the posted one (Core\Contracts\
     * SuppliesApprovedGaApplication, answered by Haziq's module). Defaults to
     * the same date gaExtensionFields() posts, so the two always agree.
     * Same records as Tests\Feature\Haziq\GaExtensionLinkTest builds.
     */
    protected function approveGaApplication(User $student, ?string $serviceEndDate = null): Application
    {
        $profile = GraGaStudentProfile::create([
            'user_id' => $student->id,
            'level_of_study' => 'master',
            'programme' => 'MSc Data Science',
            'admission_date' => '2025-09-01',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'ga_application',
            'status' => Application::STATUS_APPROVED,
            'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'service_start_date' => now()->subMonths(6),
            'service_end_date' => $serviceEndDate ?? now()->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'proposed_allowance' => 1500,
        ]);

        return $application;
    }

    /** @return array<string, mixed> */
    protected function gaExtensionFields(array $overrides = []): array
    {
        $currentEnd = now()->addMonthNoOverflow()->endOfMonth();

        return $overrides + [
            'current_end_date' => $currentEnd->toDateString(),
            'requested_new_end_date' => $currentEnd->copy()->addDay()->addMonthsNoOverflow(6)->subDay()->toDateString(),
            'reason_for_extension' => 'Fieldwork at the Gurun plant slipped two semesters '
                .'and the remaining analysis needs the assistantship to continue.',
            'mode_of_study' => 'full_time',
            'study_type' => 'research',
            'admission_date' => now()->subMonths(8)->startOfMonth()->toDateString(),
            'candidature_period' => 'Mar 2026 – Feb 2028',
            'level_of_study' => 'msc',
            'contact_no' => '+60 12-345 6789',
            'contact_email' => 'danial.personal@gmail.test',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1122 3344 5566',
            'identity_number' => '010203-08-1234',
            'holds_visa' => '0',
            'declaration' => '1',
        ];
    }

    /** @return array<string, array<int, UploadedFile>> */
    protected function gaExtensionDocuments(): array
    {
        $files = [];

        foreach (array_keys(config('nureen.ga_extension.required_documents')) as $field) {
            $files[$field] = [UploadedFile::fake()->create(str_replace('_documents', '', $field).'.pdf', 40, 'application/pdf')];
        }

        return $files;
    }
}
