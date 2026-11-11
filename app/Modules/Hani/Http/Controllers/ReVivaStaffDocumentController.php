<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\ReVivaLetters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The staff-only re-viva files: Senior Executive, Non-Executive CGS and the
 * Dean (the route's roles). Never a student or an Academic Executive. Every
 * open is logged. ?inline=1 frames the PDF on a card.
 */
class ReVivaStaffDocumentController extends Controller
{
    public function download(Request $request, ReVivaStaffDocument $document)
    {
        $user = $request->user();
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        activity('document')->causedBy($user)->performedOn($document)
            ->withProperties(['action' => 'downloaded', 'kind' => $document->kind])
            ->log('Re-viva staff document downloaded');

        if ($request->boolean('inline')) {
            return Storage::disk('local')->response($document->path, $document->original_name, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'], 'inline');
        }

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function resend(Request $request, ReVivaStaffDocument $document, ReVivaLetters $letters)
    {
        // Who may, and when: resendBlock(), re-checked in resend() under a lock.
        abort_if($document->kind !== ReVivaStaffDocument::LETTER, 404);

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
