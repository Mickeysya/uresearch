<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Models\TravelTaPlan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * One Travel, Claims or Publication application, for an approver to keep:
 *
 *   pdf()  an Application Summary -- everything the student filled in, the
 *          approval history, and the list of attachments.
 *   zip()  that summary plus every attached file, in one download.
 *
 * Who gets what, as agreed with CGS:
 *
 *   CGS (Non-Exec, Manager, Senior Director)  the summary and the ZIP.
 *   Supervisor                                the ZIP of their own student's
 *                                             files, with no summary in it.
 *   Chair, Dean                               nothing to download.
 *
 * Who may download WHICH application is the
 * applications list's own rule (visibleTo), and nothing wider: a pack never
 * shows more than the list does. In particular it does NOT also allow
 * "whatever is on the reader's stage now" -- Core's queues are scoped by role
 * alone, so that would hand every supervisor every student's pack (the open
 * "Scope approver queues" item in TODO.md). The buttons are shown only where
 * canDownload() says yes, so a queue row the list would hide has none.
 *
 * The files inside the ZIP are filtered again through Core's per-document
 * rule, ApplicationDocument::visibleTo(). That rule is stricter for CGS: CGS
 * sees an application on the list before it reaches CGS, but cannot open its
 * files until it does. The ZIP then holds the summary alone and says why,
 * rather than this module quietly overriding a Core access rule.
 */
class ApplicationPackController extends ApplicationOverviewController
{
    /** May read the whole application: its summary and every file. */
    public const SUMMARY_ROLES = ApplicationReportController::REPORT_ROLES;

    /** May download the files the student uploaded, without the summary. */
    public const FILE_ROLES = [...self::SUMMARY_ROLES, Role::SUPERVISOR];

    public function pdf(Request $request, ModuleRegistry $registry, Application $application): Response
    {
        $this->authorizePack($request, $registry, $application);
        abort_unless($this->canDownloadSummary($request, $application), 403);

        $this->log($request, $application, 'downloaded the application summary');

        return response($this->summaryPdf($request, $application), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->baseName($application).'-summary.pdf"',
        ]);
    }

    public function zip(Request $request, ModuleRegistry $registry, Application $application): BinaryFileResponse
    {
        $this->authorizePack($request, $registry, $application);

        $files = $this->openableDocuments($request, $application);

        $path = tempnam(sys_get_temp_dir(), 'pack');
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Could not build the ZIP file.');

        $withSummary = $this->canDownloadSummary($request, $application);

        if ($withSummary) {
            $zip->addFromString('00-Application-Summary.pdf', $this->summaryPdf($request, $application, $files));
        }

        $used = [];

        foreach ($files->values() as $index => $document) {
            // Numbered and labelled, so ten files all called "EXAMPLE.pdf"
            // stay apart and say which upload each one is.
            $name = sprintf('%02d-%s-%s', $index + 1, Str::slug($document->doc_type), $this->safeName($document->original_name));
            $name = isset($used[$name]) ? $document->id.'-'.$name : $name;
            $used[$name] = true;

            $zip->addFile(Storage::disk('local')->path($document->path), $name);
        }

        // ZipArchive writes no file at all for an archive with no entries.
        if ($files->isEmpty() && ! $withSummary) {
            $zip->addFromString('No-files.txt', 'There are no files on this application for you to open yet.');
        }

        $zip->close();

        $this->log($request, $application, 'downloaded all documents', ['files' => $files->count()]);

        return response()
            ->download($path, $this->baseName($application).($withSummary ? '-documents.zip' : '-files.zip'), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    protected function authorizePack(Request $request, ModuleRegistry $registry, Application $application): void
    {
        abort_if(! in_array($application->module_type, self::MODULES, true), 404);
        abort_unless($this->canDownload($request, $application), 403);
    }

    /** CGS only: the summary is the whole application, for the office that runs it. */
    public function canDownloadSummary(Request $request, Application $application): bool
    {
        return $request->user()->hasRole(...self::SUMMARY_ROLES) && $this->canDownload($request, $application);
    }

    /**
     * A CGS role or the supervisor, for one of the three modules, in a module
     * this reader approves, and on their applications list -- which also
     * rules out drafts, and limits a supervisor to their own students.
     */
    public function canDownload(Request $request, Application $application): bool
    {
        if (! $request->user()->hasRole(...self::FILE_ROLES)) {
            return false;
        }

        $slug = array_search($application->module_type, self::MODULES, true);

        if ($slug === false || ! self::approves(app(ModuleRegistry::class)->get($application->module_type), $request->user())) {
            return false;
        }

        return $this->visibleTo($request, [$slug => $application->module_type])
            ->whereKey($application->id)
            ->exists();
    }

    /** The attachments this reader may open, by Core's per-document rule. */
    protected function openableDocuments(Request $request, Application $application)
    {
        return $application->documents()->orderBy('id')->get()
            ->filter(fn (ApplicationDocument $document) => $document->visibleTo($request->user()) && $document->exists())
            ->values();
    }

    protected function summaryPdf(Request $request, Application $application, $included = null): string
    {
        $application->loadMissing(['student.supervisor', 'history.approver', 'documents']);

        return Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('norhanis::pack.summary_pdf', [
                'application' => $application,
                'student' => $application->student,
                'module' => $application->module(),
                'stage' => $application->isOpen() ? $application->currentStage() : null,
                'history' => $application->history->sortBy('created_at')->values(),
                'documents' => $application->documents->sortBy('id')->values(),
                // On the ZIP's copy, which of the files are inside it.
                'included' => $included?->pluck('id')->all(),
                'travel' => TravelDetail::where('application_id', $application->id)->first(),
                'taPlan' => TravelTaPlan::with('dates')->where('application_id', $application->id)->first(),
                'claim' => ClaimsDetail::where('application_id', $application->id)->first(),
                'publication' => PublicationDetail::with('authors', 'evaluations')
                    ->where('application_id', $application->id)->first(),
                'generatedBy' => $request->user(),
            ])
            ->output();
    }

    /** "TRAV-2026-00613": the reference the rest of the portal uses. */
    protected function baseName(Application $application): string
    {
        return $application->reference();
    }

    /** A student's file name, kept readable but safe as a ZIP entry. */
    protected function safeName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = Str::limit(Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'file', 60, '');

        return $extension !== '' ? $stem.'.'.Str::lower($extension) : $stem;
    }

    /** Recorded beside single-file views, which DocumentController logs. */
    protected function log(Request $request, Application $application, string $what, array $extra = []): void
    {
        activity('document')
            ->causedBy($request->user())
            ->performedOn($application)
            ->withProperties([
                'action' => $what,
                'application_id' => $application->id,
                'student' => $application->student?->name,
            ] + $extra)
            ->log(ucfirst($what));
    }
}
