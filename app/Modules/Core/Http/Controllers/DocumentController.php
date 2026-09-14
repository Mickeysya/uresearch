<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\ApplicationDocument;
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
        $user = $request->user();
        $application = $document->application;

        $mayView = $application->student_id === $user->id
            || $user->hasRole(Role::ADMIN)
            || $application->currentStage()?->role === $user->role
            || $application->history()->where('approver_id', $user->id)->exists();

        abort_unless($mayView, 403);
        abort_unless($document->exists(), 404);

        // Who opened whose document, and when. `approval_history` records
        // decisions only, so without this a view leaves no trace at all --
        // the specific gap docs/scope/jason.md 1.4 asks to close.
        activity('document')
            ->causedBy($user)
            ->performedOn($document)
            ->withProperties([
                'action' => 'viewed',
                'doc_type' => $document->doc_type,
                'application_id' => $application->id,
                'student' => $application->student?->name,
            ])
            ->log('Viewed document');

        return Storage::disk('local')->download($document->path, $document->original_name);
    }
}
