<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The examiner's link from their correction letter: the corrected thesis and
 * the Summary of Corrections, with no login.
 *
 * The signature is the credential: ValidPanelLink refuses a tampered URL
 * (403) or an expired one (410) before this runs. This then re-checks that the examiner was
 * sent a letter for this round and still holds a main seat (a declined
 * examiner's link stops working), and that only those two documents of this
 * round's application are served. Every download is logged.
 */
class ReVivaPanelLinkController extends Controller
{
    public function documents(Request $request, ReVivaDetail $detail, Examiner $examiner)
    {
        $this->authorise($detail, $examiner);

        $expires = Carbon::createFromTimestamp((int) $request->query('expires'));
        $documents = $this->documentsOf($detail)->map(fn (ApplicationDocument $doc) => [
            'label' => $doc->doc_type,
            'name' => $doc->original_name,
            'url' => URL::temporarySignedRoute('reviva.panel.download', $expires, [
                'detail' => $detail->id, 'examiner' => $examiner->id, 'document' => $doc->id,
            ]),
        ]);

        return view('hani::re_viva.panel_link', [
            'detail' => $detail,
            'student' => $detail->application->student,
            'examiner' => $examiner,
            'documents' => $documents,
            'expires' => $expires,
        ]);
    }

    public function download(Request $request, ReVivaDetail $detail, Examiner $examiner, ApplicationDocument $document)
    {
        $this->authorise($detail, $examiner);
        abort_unless($this->documentsOf($detail)->contains('id', $document->id), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        activity('document')->performedOn($document)
            ->withProperties(['action' => 'viewed', 'via' => 're_viva_examiner_link', 'examiner_id' => $examiner->id, 'ip' => $request->ip()])
            ->log('Re-viva document opened from an examiner link');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type ?: 'application/pdf',
        ]);
    }

    protected function authorise(ReVivaDetail $detail, Examiner $examiner): void
    {
        // A letter already emailed carries the id of the record it was sent to;
        // if that record has since been merged, the person is the record kept.
        $examiner = ExaminerMerge::resolve($examiner);

        $lettered = ReVivaStaffDocument::where('re_viva_detail_id', $detail->id)
            ->where('kind', ReVivaStaffDocument::LETTER)
            ->where('examiner_id', $examiner->id)
            ->exists();

        $current = collect($detail->currentMains())->contains(fn (Examiner $e) => $e->id === $examiner->id);

        abort_unless($lettered && $current, 403, 'This link is no longer valid. Please contact the Centre for Graduate Studies.');
    }

    protected function documentsOf(ReVivaDetail $detail)
    {
        return $detail->application->documents()
            ->whereIn('doc_type', [ReVivaController::DOC_THESIS, ReVivaController::DOC_SUMMARY])
            ->orderBy('id')
            ->get();
    }
}
