<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Http\UploadedFile;

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

    public function attach(Application $application, UploadedFile $file, string $docType): ApplicationDocument
    {
        // store() generates a random filename and keeps the extension only;
        // the student's original name is kept as metadata for display.
        $path = $file->store("applications/{$application->id}", 'local');

        return $application->documents()->create([
            'doc_type' => $docType,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }
}
