<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Support\FilePreview;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads are stored on the private disk and only ever streamed back through
 * here, so an uploaded file can never be executed by the web server.
 */
class DocumentController extends Controller
{
    public function show(Request $request, ApplicationDocument $document)
    {
        $this->authorizeAndLog($request, $document, 'viewed', 'Viewed document');

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    /**
     * The same file, inline, for the in-app viewer (components/file-preview).
     * Same rule and the same audit trail as show(). A file no browser can
     * render (Word, above all) is simply downloaded.
     */
    public function view(Request $request, ApplicationDocument $document)
    {
        $mime = FilePreview::inlineMime($document->original_name);

        if ($mime === null) {
            return $this->show($request, $document);
        }

        $this->authorizeAndLog($request, $document, 'previewed', 'Previewed document');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    protected function authorizeAndLog(Request $request, ApplicationDocument $document, string $action, string $message): void
    {
        $user = $request->user();
        $application = $document->application;

        // The rule itself now lives on the model, because the Documents page
        // has to apply the same one when deciding what to list.
        abort_unless($document->visibleTo($user), 403);
        abort_unless($document->exists(), 404);

        // Who opened whose document, and when. `approval_history` records
        // decisions only, so without this a view leaves no trace at all --
        // the specific gap docs/scope/jason.md 1.4 asks to close.
        activity('document')
            ->causedBy($user)
            ->performedOn($document)
            ->withProperties([
                'action' => $action,
                'doc_type' => $document->doc_type,
                'application_id' => $application->id,
                'student' => $application->student?->name,
            ])
            ->log($message);
    }
}
