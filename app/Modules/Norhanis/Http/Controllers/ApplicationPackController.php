<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
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
 *   documents()  every attached file as a list, or one on its own page.
 *   preview()  one attached file, opened in the browser instead of saved.
 *
 * Every approver in the application's chain gets the summary and the ZIP
 * (Norhanis, Nov 2026; this replaced an earlier CGS-only split):
 *
 *   CGS (Non-Exec, Manager, Senior Director)  any submitted application,
 *                                             as on the applications list.
 *   Supervisor                                their own students' only.
 *   Chair                                     their own department's only,
 *   Dean                                      and only once it has reached
 *                                             them: on their stage now, or
 *                                             decided by them before.
 *
 * Supervisor and Chair are scoped to their own students and department on
 * purpose: Core's queues are scoped by role alone, so "on the reader's stage"
 * by itself would hand every supervisor every student's pack (the open
 * "Scope approver queues" item in TODO.md). The buttons are shown only where
 * canDownload() says yes, so a queue row outside that scope has none.
 *
 * The files inside the ZIP are filtered again through Core's per-document
 * rule, ApplicationDocument::visibleTo(). That rule is stricter for CGS: CGS
 * sees an application on the list before it reaches CGS, but cannot open its
 * files until it does. The ZIP then holds the summary alone and says why,
 * rather than this module quietly overriding a Core access rule.
 */
class ApplicationPackController extends ApplicationOverviewController
{
    /** Opened in the browser by preview(); anything else is downloaded instead. */
    public const PREVIEWABLE = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function pdf(Request $request, ModuleRegistry $registry, Application $application): Response
    {
        $this->authorizePack($request, $registry, $application);

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

        $zip->addFromString('00-Application-Summary.pdf', $this->summaryPdf($request, $application, $files));

        $used = [];

        foreach ($files->values() as $index => $document) {
            // Numbered and labelled, so ten files all called "EXAMPLE.pdf"
            // stay apart and say which upload each one is.
            $name = sprintf('%02d-%s-%s', $index + 1, Str::slug($document->doc_type), $this->safeName($document->original_name));
            $name = isset($used[$name]) ? $document->id.'-'.$name : $name;
            $used[$name] = true;

            $zip->addFile(Storage::disk('local')->path($document->path), $name);
        }

        $zip->close();

        $this->log($request, $application, 'downloaded all documents', ['files' => $files->count()]);

        return response()
            ->download($path, $this->baseName($application).'-documents.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** Every document this reader may open, as a list, or one of them on its own. */
    public function documents(Request $request, ModuleRegistry $registry, Application $application)
    {
        $this->authorizePack($request, $registry, $application);

        $chosen = $request->validate(['document' => ['nullable', 'integer']])['document'] ?? null;
        $documents = $this->openableDocuments($request, $application);

        return view('norhanis::pack.documents', [
            'application' => $application,
            'documents' => $documents,
            // No document chosen, or one this reader may not open: the list.
            'selected' => $chosen ? $documents->firstWhere('id', $chosen) : null,
            'pack' => $this,
        ]);
    }

    /**
     * One attached file, shown in the browser. Only PDFs and images, typed
     * from the stored file's extension rather than from what the upload
     * claimed, with nosniff so the browser cannot treat it as anything else.
     * Anything else is downloaded through Core's own link instead. (No CSP
     * sandbox: Chrome refuses to open a PDF under one.)
     */
    public function preview(Request $request, ApplicationDocument $document): Response
    {
        $application = $document->application;

        abort_unless($application && in_array($application->module_type, self::MODULES, true), 404);
        abort_unless($this->canDownload($request, $application) && $document->visibleTo($request->user()), 403);
        abort_unless($document->exists(), 404);
        abort_unless($type = self::previewType($document), 415);

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->withProperties([
                'action' => 'previewed',
                'doc_type' => $document->doc_type,
                'application_id' => $application->id,
                'student' => $application->student?->name,
            ])
            ->log('Previewed document');

        return Storage::disk('local')->response($document->path, $this->safeName($document->original_name), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** What a document's icon says it is: "PDF", "JPG", "DOC". */
    public static function fileLabel(ApplicationDocument $document): string
    {
        $extension = Str::upper(pathinfo($document->path, PATHINFO_EXTENSION));

        return ['JPEG' => 'JPG', 'DOCX' => 'DOC', 'XLSX' => 'XLS'][$extension] ?? $extension;
    }

    /** The type a document is previewed as, or null if it is not previewed. */
    public static function previewType(ApplicationDocument $document): ?string
    {
        return self::PREVIEWABLE[Str::lower(pathinfo($document->path, PATHINFO_EXTENSION))] ?? null;
    }

    protected function authorizePack(Request $request, ModuleRegistry $registry, Application $application): void
    {
        abort_if(! in_array($application->module_type, self::MODULES, true), 404);
        abort_unless($this->canDownload($request, $application), 403);
    }

    /**
     * An approver in this application's chain, for a submitted Travel, Claims
     * or Publication application -- see the rule at the top of this class.
     */
    public function canDownload(Request $request, Application $application): bool
    {
        $user = $request->user();

        if (! $user->hasRole(...self::ROLES)
            || $application->status === Application::STATUS_DRAFT
            || ! in_array($application->module_type, self::MODULES, true)
            || ! self::approves($application->module(), $user)) {
            return false;
        }

        if (Role::isCgs($user->role)) {
            return true;
        }

        $student = $application->student;

        $own = match ($user->role) {
            Role::SUPERVISOR => $student?->supervisor_id === $user->id,
            // A student or Chair with no department on record matches nobody.
            Role::CHAIR => $student?->department !== null && $student->department === $user->department,
            default => true,
        };

        return $own && $this->reached($application, $user);
    }

    /** On this reader's stage now, or decided by them before. */
    protected function reached(Application $application, User $user): bool
    {
        return ($application->isOpen() && $application->currentStage()?->role === $user->role)
            || $application->history()->where('approver_id', $user->id)->exists();
    }

    /** The attachments this reader may open, by Core's per-document rule. */
    public function openableDocuments(Request $request, Application $application)
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
