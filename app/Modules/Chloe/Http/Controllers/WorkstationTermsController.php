<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Support\WorkstationHeaderLinks;
use App\Modules\Core\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * PART 4 #1. Public, not gated by `auth` -- every email this module sends
 * (Email 1, Termination Notice, Workstation Assigned/Changed) links here,
 * and a link in an email has to work for whoever opens it. The content
 * itself is a transcription of the original PDF (see
 * docs/knowledge/Postgraduate Workstation - Term of Use (First Edition).md
 * for where the text came from).
 *
 * The real file has never actually been placed in this repo -- see
 * findOriginalPdf() below, which does not insist on one exact filename:
 * any .pdf in ORIGINAL_PDF_DIRECTORY whose name contains "Term of Use"
 * (either "... First Edition.pdf" or "... (First Edition).pdf" both
 * match) is accepted. downloadPdf() serves it directly the moment one
 * exists; until then it falls back to rendering the same content through
 * Dompdf, which is what terms.blade.php's own grey disclosure line is
 * about.
 */
class WorkstationTermsController extends Controller
{
    public const ORIGINAL_PDF_DIRECTORY = 'docs/knowledge';

    public function show()
    {
        $links = WorkstationHeaderLinks::forCurrentVisitor();

        return view('chloe::workstation.terms', [
            'hasOriginalPdf' => self::findOriginalPdf() !== null,
            'homeUrl' => $links['home'],
            'backUrl' => $links['back'],
        ]);
    }

    public function downloadPdf(Request $request)
    {
        $name = 'Postgraduate Workstation - Term of Use (First Edition).pdf';
        // ?inline=1 is Core's in-app viewer (components/doc-link).
        $inline = $request->boolean('inline');

        if ($original = self::findOriginalPdf()) {
            return $inline
                ? response()->file($original, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"'])
                : response()->download($original, $name);
        }

        $pdf = Pdf::loadView('chloe::workstation.terms-pdf');

        return $inline ? $pdf->stream($name) : $pdf->download($name);
    }

    /**
     * Any .pdf in the directory whose name contains "term of use",
     * matched case-insensitively -- not one exact filename, since the
     * two spellings people actually use ("... First Edition.pdf" and
     * "... (First Edition).pdf") both have to work. The first match,
     * alphabetically, if more than one file ever qualifies.
     */
    protected static function findOriginalPdf(): ?string
    {
        $directory = base_path(self::ORIGINAL_PDF_DIRECTORY);

        if (! is_dir($directory)) {
            return null;
        }

        $match = collect(scandir($directory))
            ->filter(fn ($name) => str_ends_with(mb_strtolower($name), '.pdf') && str_contains(mb_strtolower($name), 'term of use'))
            ->sort()
            ->first();

        return $match ? $directory.DIRECTORY_SEPARATOR.$match : null;
    }
}
