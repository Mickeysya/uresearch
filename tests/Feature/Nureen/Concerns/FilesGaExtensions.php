<?php

namespace Tests\Feature\Nureen\Concerns;

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
