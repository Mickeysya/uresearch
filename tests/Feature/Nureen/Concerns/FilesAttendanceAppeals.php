<?php

namespace Tests\Feature\Nureen\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The documents CGS requires on every attendance appeal
 * (config('nureen.attendance_appeal.required_documents')), as fake uploads
 * on a fake disk -- nothing reaches real storage.
 */
trait FilesAttendanceAppeals
{
    protected function setUpFilesAttendanceAppeals(): void
    {
        Storage::fake('local');
    }

    /** @return array<string, array<int, UploadedFile>> */
    protected function appealDocuments(): array
    {
        $files = [];

        foreach (array_keys(config('nureen.attendance_appeal.required_documents')) as $field) {
            $files[$field] = [UploadedFile::fake()->create("{$field}.pdf", 40, 'application/pdf')];
        }

        return $files;
    }
}
