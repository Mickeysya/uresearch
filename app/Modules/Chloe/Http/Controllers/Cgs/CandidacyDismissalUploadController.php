<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\CandidacyDismissalImport;
use App\Modules\Chloe\Services\CandidacyDismissalUpload as Upload;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Dismissal Upload (Registry and CGS roles): the Registry's result comes back as a spreadsheet; step 1 previews it (nothing saved), step 2 confirms.
 * The parsed Student IDs wait in the session between the two steps (no file is stored); confirm re-checks every row.
 */
class CandidacyDismissalUploadController extends Controller
{
    private const SESSION = 'candidacy.dismissal.upload';

    public function show(Request $request, Upload $upload)
    {
        $pending = $request->session()->get(self::SESSION);
        $classified = $pending ? $upload->classify($pending['rows']) : null;

        return view('chloe::candidacy.cgs.dismissal-upload', [
            'pending' => $pending, 'rows' => $classified, 'counts' => $classified ? $upload->counts($classified) : null, 'labels' => Upload::LABELS,
            'recent' => CandidacyDismissalImport::with('uploader')->orderByDesc('uploaded_at')->orderByDesc('id')->limit(10)->get(),
            'result' => $request->session()->get('dismissal_result'),
        ]);
    }

    /** Step 1: read and classify. Nothing is saved. */
    public function preview(Request $request, Upload $upload)
    {
        $request->validate(['file' => ['required', 'file', 'max:'.Upload::MAX_KB]], ['file.required' => 'Choose a file to upload.', 'file.max' => 'The file is larger than 5 MB.']);

        $rows = $upload->parse($request->file('file'));
        $willIds = collect($upload->classify($rows))->where('status', Upload::WILL)->pluck('id')->all();
        $request->session()->put(self::SESSION, ['rows' => $rows, 'file' => mb_substr($request->file('file')->getClientOriginalName(), 0, 250), 'will' => $willIds]);

        return redirect()->route('candidacy.cgs.dismissal-upload');
    }

    /** Step 2: apply. */
    public function confirm(Request $request, Upload $upload)
    {
        $pending = $request->session()->get(self::SESSION);

        if (! $pending) {
            return redirect()->route('candidacy.cgs.dismissal-upload')->with('wca_status', 'There is no preview to confirm. Upload the file again.');
        }

        $wasToDismiss = (array) ($pending['will'] ?? []);
        $import = $upload->apply($pending['rows'], $pending['file'], $request->user());
        $done = $import->rows()->pluck('student_code')->all();
        $request->session()->forget(self::SESSION);

        return redirect()->route('candidacy.cgs.dismissal-upload')->with('dismissal_result', [
            'file' => $import->file_name, 'rows' => $import->rows_total, 'dismissed' => $import->dismissed, 'skipped' => $import->skipped,
            'changed' => count(array_diff($wasToDismiss, $done)),
        ]);
    }

    /** Throw the preview away. */
    public function discard(Request $request)
    {
        $request->session()->forget(self::SESSION);

        return redirect()->route('candidacy.cgs.dismissal-upload');
    }
}
