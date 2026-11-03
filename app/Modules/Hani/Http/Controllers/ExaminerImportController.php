<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Exports\ExaminerTemplateExport;
use App\Modules\Hani\Imports\PoolWorkbookImport;
use App\Modules\Hani\Models\ExaminerImportBatch;
use App\Modules\Hani\Models\ExaminerReviewItem;
use App\Modules\Hani\Support\Import\PoolImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Pool import: CGS's own Excel pool list in, the examiner pool out, with a
 * preview in between. Senior Executive CGS only (routes.php).
 *
 * Upload -> preview -> confirm. The file is read straight from the request's
 * temporary upload and never written anywhere; only the parsed rows are kept,
 * in examiner_import_batches, until confirmed or purged. That is why this does
 * not go through DocumentStore -- there is no document to keep, and no
 * application for one to belong to.
 */
class ExaminerImportController extends Controller
{
    public function create()
    {
        return view('hani::examiner_admin.import', [
            'batch' => null,
            'openReviewItems' => ExaminerReviewItem::open()->count(),
        ]);
    }

    public function store(Request $request, PoolImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:'.(int) config('hani.import.max_kb', 5120)],
        ], [
            'file.mimes' => 'Upload the pool as an Excel workbook (.xlsx) or a CSV file.',
        ]);

        // Previews nobody confirmed do not pile up.
        ExaminerImportBatch::purgeStale();

        $file = $request->file('file');

        try {
            $sheets = Excel::toArray(new PoolWorkbookImport(), $file);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['file' => 'That file could not be read as a spreadsheet.']);
        }

        $read = $importer->read($sheets);

        if ($read['layout'] === null || $read['rows'] === []) {
            throw ValidationException::withMessages([
                'file' => $read['messages'][0] ?? 'No examiner rows found. The file must use CGS\'s Internal/External Examiner layout or the UResearch template.',
            ]);
        }

        $batch = ExaminerImportBatch::create([
            'user_id' => $request->user()->id,
            'layout' => $read['layout'],
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'rows' => array_map(fn ($row) => $row->toArray(), $read['rows']),
            'messages' => $read['messages'],
        ]);

        return redirect()->route('examiner-import.show', $batch);
    }

    public function show(Request $request, ExaminerImportBatch $batch, PoolImporter $importer)
    {
        $this->authoriseBatch($request, $batch);

        $rows = $batch->poolRows();
        $classified = $importer->classify($rows);

        $counts = array_fill_keys(array_keys(PoolImporter::LABELS), 0);
        $missingEmail = 0;

        foreach ($classified as $result) {
            $counts[$result['status']]++;
            $missingEmail += (int) ($result['missing_email'] && $result['status'] !== PoolImporter::REJECTED);
        }

        return view('hani::examiner_admin.import', [
            'batch' => $batch,
            'rows' => $rows,
            'classified' => $classified,
            'counts' => $counts,
            'missingEmail' => $missingEmail,
            'openReviewItems' => ExaminerReviewItem::open()->count(),
        ]);
    }

    public function confirm(Request $request, ExaminerImportBatch $batch, PoolImporter $importer): RedirectResponse
    {
        $this->authoriseBatch($request, $batch);

        $summary = $importer->apply($batch->poolRows(), $batch->id, $request->user());
        $batch->update(['summary' => $summary, 'confirmed_at' => now()]);

        $parts = array_filter([
            $summary[PoolImporter::NEW] + $summary[PoolImporter::DUPLICATE] ? ($summary[PoolImporter::NEW] + $summary[PoolImporter::DUPLICATE]).' added' : null,
            $summary[PoolImporter::UPDATE] ? $summary[PoolImporter::UPDATE].' updated' : null,
            $summary[PoolImporter::UNCHANGED] ? $summary[PoolImporter::UNCHANGED].' unchanged' : null,
            $summary[PoolImporter::REJECTED] ? $summary[PoolImporter::REJECTED].' rejected' : null,
            $summary['missing_email'] ? $summary['missing_email'].' still need an email' : null,
            $summary['review_items'] ? $summary['review_items'].' to check in the review list' : null,
        ]);

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', 'Pool import applied: '.implode(', ', $parts).'.');
    }

    public function template()
    {
        return Excel::download(new ExaminerTemplateExport(), 'uresearch-examiner-pool-template.xlsx');
    }

    /** What the import found for a person to check. */
    public function review()
    {
        return view('hani::examiner_admin.review', [
            'items' => ExaminerReviewItem::open()->with('examiner')->latest()->get(),
        ]);
    }

    public function resolve(Request $request, ExaminerReviewItem $item): RedirectResponse
    {
        abort_if($item->resolved_at !== null, 404);

        $item->update(['resolved_at' => now(), 'resolved_by_id' => $request->user()->id]);

        return redirect()->route('examiner-import.review')->with('status', 'Marked as checked.');
    }

    /** Your own unconfirmed preview, and nobody else's. */
    protected function authoriseBatch(Request $request, ExaminerImportBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
        abort_if($batch->confirmed_at !== null, 404);
    }
}
