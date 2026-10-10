<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only sanctioned way to attach a file to an application.
 *
 * Call it from your module's store() after validating with `->file(...)`.
 * Files land on the private `local` disk under storage/app, never under
 * public/, and are renamed to a random string so an uploaded ".php" cannot
 * be requested back as a script.
 */
class DocumentStore
{
    /** Extensions the portal accepts. Everything else is rejected outright. */
    public const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public const MAX_KB = 10240; // 10 MB

    /**
     * Extensions, per-file size and file count for a *multi*-file field --
     * narrower than ALLOWED/MAX_KB above, which several single-document
     * fields across other modules already rely on. See manyRules().
     */
    public const MULTI_ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

    public const MULTI_MAX_FILES = 5;

    public const MULTI_MAX_FILE_KB = 5120; // 5 MB per file

    public const MULTI_MAX_TOTAL_KB = 20480; // 20 MB combined

    /**
     * Laravel validation rules to apply to the request field before calling attach().
     *
     * @return array<int, string>
     */
    public static function rules(bool $required = false): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'mimes:'.implode(',', self::ALLOWED),
            'max:'.self::MAX_KB,
        ]);
    }

    /**
     * The same idea as rules(), for a field that accepts several files at
     * once (`name="field[]" multiple`) -- a minimum count, a maximum count,
     * a per-file size cap, an allowed-type list, and a combined size cap
     * Laravel has no rule string for, so it is a closure here.
     *
     * Returns both keys `validate()` needs: the array field itself
     * (required/nullable, array, min, max count, the combined-size check)
     * and `{$field}.*` (each file: must be a file, the allowed types, the
     * per-file size). Merge the result into your own rules array.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function manyRules(
        string $field,
        bool $required = true,
        int $maxFiles = self::MULTI_MAX_FILES,
        int $maxFileKb = self::MULTI_MAX_FILE_KB,
        int $maxTotalKb = self::MULTI_MAX_TOTAL_KB,
        array $allowed = self::MULTI_ALLOWED,
    ): array {
        return [
            $field => [
                $required ? 'required' : 'nullable',
                'array',
                'min:1',
                'max:'.$maxFiles,
                function (string $attribute, $value, \Closure $fail) use ($maxTotalKb) {
                    $totalKb = collect($value)
                        ->filter(fn ($file) => $file instanceof UploadedFile)
                        ->sum(fn (UploadedFile $file) => $file->getSize()) / 1024;

                    if ($totalKb > $maxTotalKb) {
                        $fail('The combined size of all files must not exceed '.round($maxTotalKb / 1024).' MB.');
                    }
                },
            ],
            $field.'.*' => [
                'file',
                'mimes:'.implode(',', $allowed),
                'max:'.$maxFileKb,
            ],
        ];
    }

    public function attach(Application $application, UploadedFile $file, string $docType): ApplicationDocument
    {
        // store() generates a random filename and keeps the extension only;
        // the student's original name is kept as metadata for display.
        $path = $file->store("applications/{$application->id}", 'local');

        $document = $application->documents()->create([
            'doc_type' => $docType,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->withProperties([
                'action' => 'uploaded',
                'doc_type' => $docType,
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'application_id' => $application->id,
            ])
            ->log('Uploaded document');

        return $document;
    }

    /**
     * attach(), for a field validated with manyRules() -- one ApplicationDocument
     * row per file, in selection order, each going through the same
     * store-on-the-private-disk-then-log path as a single attach().
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, ApplicationDocument>
     */
    public function attachMany(Application $application, array $files, string $docType): Collection
    {
        return collect($files)->map(fn (UploadedFile $file) => $this->attach($application, $file, $docType));
    }

    /**
     * Save system-generated bytes (a rendered Dompdf certificate, for
     * instance) as an ApplicationDocument, the same way an upload would land.
     *
     * Kept separate from attach() because there is no UploadedFile to read a
     * size/mime type from -- the caller already knows what it produced.
     */
    public function storeGenerated(
        Application $application,
        string $contents,
        string $filename,
        string $docType,
        string $mimeType = 'application/pdf',
    ): ApplicationDocument {
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
        $path = "applications/{$application->id}/".Str::random(40).'.'.$extension;

        Storage::disk('local')->put($path, $contents);

        return $application->documents()->create([
            'doc_type' => $docType,
            'original_name' => $filename,
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
        ]);
    }
}
