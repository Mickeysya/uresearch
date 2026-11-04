<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Nureen\Support\DocumentPreviews;
use App\Modules\Nureen\Support\StaffApplicationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * View an attached file in the browser instead of downloading it -- the
 * preview overlay's source (components/file-preview).
 *
 * The same rule as Core's DocumentController::show(), the download route:
 * ApplicationDocument::visibleTo(). Only documents of Nureen's own modules;
 * any other module's file is 404 here (Core's route still serves it).
 *
 * PDFs and JPG/PNG are served inline with their real Content-Type. Anything
 * else -- Word files above all -- gets a small "Preview not available" page
 * with the download link: there is no conversion. That page can't be
 * framed (Core's CSP sends frame-ancestors 'none' with every HTML page), so
 * the overlay shows its own message for those files and never loads this
 * one into the iframe; it is what a direct visit sees.
 */
class DocumentPreviewController extends Controller
{
    public function view(Request $request, ApplicationDocument $document)
    {
        $application = $document->application;

        abort_unless($application && in_array($application->module_type, StaffApplicationAccess::MODULES, true), 404);
        abort_unless($document->visibleTo($request->user()), 403);
        abort_unless($document->exists(), 404);

        $mime = DocumentPreviews::inlineMime($document->original_name);

        if ($mime === null) {
            return response()->view('nureen::documents.preview_unavailable', [
                'document' => $document,
                'isWord' => DocumentPreviews::kind($document->original_name) === DocumentPreviews::WORD,
            ]);
        }

        // Logged like a download (Core's DocumentController::show()), so a
        // view leaves the same trace.
        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->withProperties([
                'action' => 'previewed',
                'doc_type' => $document->doc_type,
                'application_id' => $application->id,
                'student' => $application->student?->name,
            ])
            ->log('Previewed document');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
