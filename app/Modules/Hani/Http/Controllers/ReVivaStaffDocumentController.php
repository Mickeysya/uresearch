<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads of the staff-only re-viva files: correction letters, signed
 * UTP/CGS/024 reports and the Confirmation of Correction.
 *
 * The Academic Executive (own department), the Senior Executive and the
 * Non-Executive CGS, who is a return contact on the letter. Never a student:
 * the route's role list does not include them. Every download is logged.
 */
class ReVivaStaffDocumentController extends Controller
{
    public function download(Request $request, ReVivaStaffDocument $document)
    {
        $user = $request->user();
        $student = $document->detail->application->student;

        abort_if($user->role === Role::ACADEMIC_EXEC && $student->department !== $user->department, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        activity('document')->causedBy($user)->performedOn($document)
            ->withProperties(['action' => 'downloaded', 'kind' => $document->kind])
            ->log('Re-viva staff document downloaded');

        return Storage::disk('local')->download($document->path, $document->original_name);
    }
}
