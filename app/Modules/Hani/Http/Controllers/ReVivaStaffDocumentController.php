<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\ReVivaLetters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Downloads of the staff-only re-viva files: correction letters, signed
 * UTP/CGS/024 reports and the Confirmation of Correction.
 *
 * The Academic Executive (own department), the Senior Executive and the
 * Non-Executive CGS, who is a return contact on the letter. Never a student:
 * the route's role list does not include them. Every download is logged.
 *
 * Also "Resend letter" (AE of the department and Senior Executive only):
 * the same letter with a fresh link, see ReVivaLetters::resend().
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

    public function resend(Request $request, ReVivaStaffDocument $document, ReVivaLetters $letters)
    {
        // Who may, and when: resendBlock(), re-checked in resend() under a lock.
        abort_if($document->kind !== ReVivaStaffDocument::LETTER, 404);
        abort_if($request->user()->role === Role::ACADEMIC_EXEC && $document->detail->application->student->department !== $request->user()->department, 403);

        try {
            $result = $letters->resend($document, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return $result['emailed']
            ? back()->with('status', "Correction letter resent to {$result['emailed']}, with a new link that works until "
                .ReVivaLetters::panelLinkExpiry($document->detail)->format('j F Y').'.')
            : back()->with('warning', $result['skipped']);
    }
}
