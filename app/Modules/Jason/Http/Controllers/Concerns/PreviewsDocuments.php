<?php

namespace App\Modules\Jason\Http\Controllers\Concerns;

use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Http\Request;

/**
 * Serving a PDF to be read rather than filed away.
 *
 * Core's `documents.show` always sends an attachment, which is right for a
 * document somebody is collecting and wrong for one they are checking: every
 * look left another copy on disk. These serve the same bytes
 * `Content-Disposition: inline`, so the browser's own viewer opens them and
 * a page can frame them, with `?download=1` when the file itself is wanted.
 *
 * Framing works because Core's CSP middleware deliberately skips non-HTML
 * responses, so a PDF carries neither `frame-ancestors` nor
 * `X-Frame-Options`; the framing page's own `default-src 'self'` allows a
 * same-origin frame.
 */
trait PreviewsDocuments
{
    /** A PDF this module generated, held only in memory. */
    protected function servePdf($pdf, string $filename, Request $request)
    {
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->disposition($request).'; filename="'.$filename.'"',
        ]);
    }

    /**
     * A document already archived on the private disk.
     *
     * Applies Core's own visibility rule -- `visibleTo()` lives on the model
     * precisely so both the Documents page and a controller can ask it -- and
     * logs the view the way `DocumentController` does. Without that logging
     * this would be a second way to open the same file that leaves no trace,
     * which is exactly the gap the audit log was added to close.
     */
    protected function serveArchived(ApplicationDocument $document, Request $request)
    {
        $user = $request->user();

        abort_unless($document->visibleTo($user), 403);
        abort_unless($document->exists(), 404);

        activity('document')
            ->causedBy($user)
            ->performedOn($document)
            ->withProperties([
                'action' => 'viewed',
                'doc_type' => $document->doc_type,
                'application_id' => $document->application_id,
                'student' => $document->application?->student?->name,
            ])
            ->log('Viewed document');

        return response(
            \Illuminate\Support\Facades\Storage::disk('local')->get($document->path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $this->disposition($request)
                    .'; filename="'.$document->original_name.'"',
            ],
        );
    }

    protected function disposition(Request $request): string
    {
        return $request->boolean('download') ? 'attachment' : 'inline';
    }
}
