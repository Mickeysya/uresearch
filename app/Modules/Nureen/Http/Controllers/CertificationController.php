<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Support\AppointmentVerifier;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\Drafts;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Support\CertificateReferenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class CertificationController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'ga_certification';
    }

    public function create(Request $request)
    {
        return $this->form($request, null);
    }

    /** Continue a saved draft: the same form, pre-filled, on the step last saved. */
    public function editDraft(Request $request, Application $application)
    {
        return $this->form($request, Drafts::ownedBy($request->user(), $application, $this->moduleKey()));
    }

    protected function form(Request $request, ?Application $draft)
    {
        return view('nureen::ga_certification.form', [
            'fields' => config('nureen.ga_certification.fields'),
            'supportingDocumentLabel' => config('nureen.ga_certification.supporting_document_label'),
            'hasSupervisor' => $request->user()->supervisor_id !== null,
            'purposeMax' => config('nureen.ga_certification.purpose_max'),
            'draft' => $draft,
            'detail' => $draft ? GaCertificationDetail::where('application_id', $draft->id)->first() : null,
            // Supporting documents, whichever type's label they were saved under.
            'attachedDocuments' => $draft ? Drafts::attached($draft)->flatten(1)->values() : collect(),
            'otherDrafts' => $draft ? collect() : Drafts::of($request->user(), $this->moduleKey()),
        ]);
    }

    /**
     * "Save draft": creates the draft on the first save, updates it after.
     * Light validation only (types, lengths, file types and sizes); files
     * attach through DocumentStore immediately; ticked "Remove" boxes take
     * files off.
     */
    public function saveDraft(Request $request, DocumentStore $documents, ?Application $application = null): RedirectResponse
    {
        if ($application) {
            Drafts::ownedBy($request->user(), $application, $this->moduleKey());
        }

        $removing = $this->removing($request, $application);

        $rules = [
            'appointment_type' => ['nullable', 'in:GA,GRA'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string', 'max:'.config('nureen.ga_certification.purpose_max')],
            'draft_step' => ['nullable', 'integer', 'min:0', 'max:20'],
        ] + $this->supportingDocumentRules($application, $removing, required: false);

        // Every type field, never required, with the same length limits.
        foreach (config('nureen.ga_certification.fields', []) as $typeFields) {
            foreach ($typeFields as $key => $field) {
                $rules[$key] = ['nullable', 'string', 'max:'.($field['max'] ?? 255)];
            }
        }

        $data = $request->validate($rules, DocumentStore::manyMessages('supporting_documents'));

        $application = DB::transaction(function () use ($request, $data, $application, $removing, $documents) {
            $application ??= Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $values = [
                'appointment_type' => $data['appointment_type'] ?? null,
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'draft_step' => $data['draft_step'] ?? null,
            ];

            // Every type field cleared, then the chosen type's filled in --
            // as submitting would (GA and GRA share 'semester', so the
            // order matters).
            foreach (config('nureen.ga_certification.fields', []) as $typeFields) {
                foreach (array_keys($typeFields) as $key) {
                    $values[$key] = null;
                }
            }

            foreach (array_keys(config('nureen.ga_certification.fields.'.($data['appointment_type'] ?? ''), [])) as $key) {
                $values[$key] = $data[$key] ?? null;
            }

            GaCertificationDetail::updateOrCreate(['application_id' => $application->id], $values);

            Drafts::removeDocuments($application, $removing);

            if ($files = $request->file('supporting_documents')) {
                $documents->attachMany($application, $files, $this->supportingDocumentLabel($data['appointment_type'] ?? null));
            }

            $application->touch();

            return $application;
        });

        return redirect()
            ->route('ga-certification.draft.edit', $application)
            ->with('status', 'Draft saved at '.now()->format('g:ia').'. Continue it any time from My Drafts.');
    }

    /** Delete a draft: its stored files, its detail row and the application row. */
    public function destroyDraft(Request $request, Application $application): RedirectResponse
    {
        Drafts::delete(Drafts::ownedBy($request->user(), $application, $this->moduleKey()), GaCertificationDetail::class);

        return redirect()->route('nureen.drafts')->with('status', 'Draft deleted.');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $student = $request->user();

        // Submitting a saved draft reuses it rather than creating a second
        // application -- only the student's own, and only while still a draft.
        $draft = $request->filled('draft_id')
            ? Drafts::ownedBy($student, Application::findOrFail((int) $request->input('draft_id')), $this->moduleKey())
            : null;

        // "Block submission with a clear message instead of creating an
        // application nobody can review" -- the new first stage is
        // addressed to the student's own named supervisor, so there has to
        // be one to address it to.
        if (! $student->supervisor_id) {
            return back()->with('error', 'No supervisor is assigned to your account. Please contact CGS.');
        }

        $data = $request->validate([
            'appointment_type' => ['required', 'in:GA,GRA'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            // Printed on the letter -- see config('nureen.ga_certification.purpose_max').
            'purpose' => ['nullable', 'string', 'max:'.config('nureen.ga_certification.purpose_max')],
        ]);

        // "The form shows only the fields for the chosen type" -- validated
        // the same way, from config('nureen.ga_certification.fields'), so
        // the form, the validation, the approver's detail view and the
        // letter can never drift on what a GA or GRA row actually carries.
        $typeFields = config("nureen.ga_certification.fields.{$data['appointment_type']}", []);
        $typeRules = [];

        foreach ($typeFields as $key => $field) {
            // 'max' keeps the letter on one page -- see the config comment.
            $typeRules[$key] = [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:'.($field['max'] ?? 255)];
        }

        $typeData = $request->validate($typeRules);

        $removing = $this->removing($request, $draft);
        $documentLabel = $this->supportingDocumentLabel($data['appointment_type']);

        // Required unless the draft already has supporting documents.
        $request->validate(
            $this->supportingDocumentRules($draft, $removing, required: true),
            DocumentStore::manyMessages('supporting_documents') + ['supporting_documents.required' => "Attach {$documentLabel}."],
        );

        $application = DB::transaction(function () use ($request, $student, $data, $typeData, $draft, $removing, $documentLabel, $engine, $documents) {
            if ($draft) {
                // Locked and re-checked, so a double-click can never submit
                // the same draft twice.
                $application = Application::whereKey($draft->id)->lockForUpdate()->first();
                abort_unless($application?->status === Application::STATUS_DRAFT, 409, 'This draft has already been submitted.');
            } else {
                $application = Application::create([
                    'student_id' => $student->id,
                    'submitted_by_id' => $student->id,
                    'module_type' => $this->moduleKey(),
                    'status' => Application::STATUS_DRAFT,
                ]);
            }

            // The other type's fields are cleared: a draft that switched
            // from GA to GRA must not carry stale course details.
            $otherFields = collect(config('nureen.ga_certification.fields', []))
                ->except($data['appointment_type'])->flatMap(fn ($fields) => array_keys($fields))
                ->diff(array_keys($typeData))
                ->mapWithKeys(fn ($key) => [$key => null])->all();

            GaCertificationDetail::updateOrCreate(['application_id' => $application->id], [
                // Snapshotted now, not re-read later -- if the student's
                // supervisor changes afterwards, this application still
                // goes to whoever it was addressed to at submission. See
                // SupervisionDetail::requested_supervisor_id for the same
                // reasoning.
                'supervisor_id' => $student->supervisor_id,
                'draft_step' => null,
            ] + $data + $typeData + $otherFields);

            Drafts::removeDocuments($application, $removing);

            // Files saved while drafting under the other type's label take
            // the submitted type's label.
            $application->documents()->update(['doc_type' => $documentLabel]);

            if ($files = $request->file('supporting_documents')) {
                $documents->attachMany($application, $files, $documentLabel);
            }

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Certification request #{$application->id} submitted.");
    }

    /** The supporting documents' label for a type, or a neutral one before a type is chosen. */
    protected function supportingDocumentLabel(?string $type): string
    {
        return config("nureen.ga_certification.supporting_document_label.{$type}") ?? 'Supporting Document';
    }

    /**
     * Required on submit unless the draft already has supporting documents
     * (after removals); never required on a draft save. The 5-file limit
     * counts the files already attached.
     *
     * @param  array<int, int>  $removing
     */
    protected function supportingDocumentRules(?Application $draft, array $removing, bool $required): array
    {
        $already = $draft ? Drafts::attached($draft, $removing)->flatten(1)->count() : 0;
        $rules = DocumentStore::manyRules('supporting_documents', $required && $already === 0);
        $rules['supporting_documents'][] = function (string $attribute, $value, \Closure $fail) use ($already) {
            if ($already + count((array) $value) > DocumentStore::MULTI_MAX_FILES) {
                $fail('You may attach at most '.DocumentStore::MULTI_MAX_FILES." supporting documents ({$already} already attached).");
            }
        };

        return $rules;
    }

    /** The ids ticked "Remove" on the form -- only meaningful on a draft. @return array<int, int> */
    protected function removing(Request $request, ?Application $draft): array
    {
        if (! $draft) {
            return [];
        }

        $ids = $request->validate(['remove_documents' => ['nullable', 'array'], 'remove_documents.*' => ['integer']])['remove_documents'] ?? [];

        return array_map('intval', $ids);
    }

    public function queue(Request $request, WorkflowEngine $engine, AppointmentVerifier $verifier, ClientCharter $charter)
    {
        // Same reasoning as SupervisionController::queue(): the engine's
        // queue is role-scoped, not person-scoped, so the 'supervisor'
        // stage is narrowed to the one person this application actually
        // names. CGS Staff and Senior Director need no such narrowing.
        // Every stage is ordered by when each row reached it, so anything
        // past its Client Charter due date sits at the top.
        $queue = $this->queueFor($request, $engine, ['student', 'history'],
            function ($query, Stage $stage) use ($request, $charter) {
                $charter->orderByStageEntry($query);

                if ($stage->key !== 'supervisor') {
                    return;
                }

                $query->whereIn('id', GaCertificationDetail::query()
                    ->where('supervisor_id', $request->user()->id)
                    ->select('application_id'));
            });

        $details = GaCertificationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        // "Before CGS staff review, the system automatically checks the
        // student's GA/GRA appointment" -- NOT part of CGS's confirmed
        // process, so gated behind config('nureen.ga_certification.
        // appointment_check_enabled'), default false. When disabled this is
        // not computed at all, so nothing about it can leak into the view.
        $appointmentChecks = config('nureen.ga_certification.appointment_check_enabled')
            ? $details->map(function (GaCertificationDetail $detail) use ($verifier, $queue) {
                $application = $queue['applications']->firstWhere('id', $detail->application_id);

                if (! $application?->student) {
                    return ['status' => AppointmentVerifier::NOT_FOUND, 'record' => null];
                }

                return $verifier->verify(
                    $application->student,
                    $detail->appointment_type,
                    $detail->period_start,
                    $detail->period_end,
                );
            })
            : collect();

        return view('nureen::ga_certification.queue', $queue + [
            'details' => $details,
            'clocks' => $queue['applications']->mapWithKeys(fn (Application $a) => [$a->id => $charter->stageClock($a)]),
            'appointmentChecks' => $appointmentChecks,
        ]);
    }

    /**
     * Every certificate ever issued, Valid or Revoked -- reachable from the
     * queue's tools slot (ga_certification/_tools.blade.php). The pending
     * queue above only ever lists applications still awaiting a decision,
     * so once the Senior Director approves one and the certificate exists,
     * this is the only place left to find it and revoke it.
     */
    public function issued(Request $request)
    {
        $details = GaCertificationDetail::whereNotNull('reference_number')
            ->with('application.student')
            ->orderByDesc('id')
            ->paginate(20);

        return view('nureen::ga_certification.issued', ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): a 'supervisor'-stage decision must
     * come from the specific supervisor this application names, not merely
     * from someone holding the Supervisor role -- same reasoning as
     * SupervisionController::decide(). The Senior Director's approval is
     * still the final stage, and that is the moment the certificate has to
     * exist -- generate it and attach it as an ApplicationDocument right
     * here, using the same download route and permission check every other
     * upload gets.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();

        if ($stage?->key === 'supervisor' && $detail?->supervisor_id !== $request->user()->id) {
            abort(403, 'This request was not addressed to you.');
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED) {
            $this->generateCertificate($decided, $documents);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Assigns the reference and stores the letter. The Senior Director it
     * names is read from approval_history by Support\LetterRenderer -- the
     * person who actually approved, not whoever happens to be passed in.
     */
    protected function generateCertificate(Application $application, DocumentStore $documents): void
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->firstOrFail();

        $detail->update(['reference_number' => app(CertificateReferenceGenerator::class)->generate()]);

        $certificate = $documents->storeGenerated(
            $application,
            app(LetterRenderer::class)->certification($application->fresh()),
            "certification-{$application->id}.pdf",
            LetterRenderer::CERTIFICATION_DOC_TYPE,
        );

        // "Generate, format, and dispatch" -- this is the dispatch. The
        // generic ApplicationDecided mail announces the approval but carries
        // nothing, so without this the student has to go and find the
        // download. The letter itself stays an ApplicationDocument behind the
        // authorised route; the email is a copy, not the record.
        $application->student?->notify(new CertificationIssued($application, $certificate));
    }

    /**
     * The Senior Director's "Preview letter" button: the exact letter with
     * their own name in the signature block, the reference shown as "To be
     * assigned on issue" and a DRAFT watermark. Writes and sends nothing.
     */
    public function previewLetter(Request $request, Application $application, WorkflowEngine $engine, LetterRenderer $letters)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($engine->currentStage($application)?->key === 'senior_director', 404, 'This letter is not awaiting final approval.');

        return response($letters->certification($application, draft: true, previewer: $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certification-'.$application->id.'-draft.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Marks the certificate invalid without touching the application the
     * way an approval chain would -- the application stays approved, this
     * is only about whether the PDF already issued is still trusted. The
     * public verification page is what this is actually for.
     */
    public function revoke(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = GaCertificationDetail::where('application_id', $application->id)->firstOrFail();

        abort_if($detail->reference_number === null, 404, 'No certificate has been generated for this application.');

        $data = $request->validate([
            'revoked_reason' => ['required', 'string', 'max:255'],
        ], [
            'revoked_reason.required' => 'Say why this certificate is being revoked — it is shown nowhere public, but is kept for the record.',
        ]);

        $detail->update([
            'revoked_at' => now(),
            'revoked_reason' => $data['revoked_reason'],
        ]);

        // The stored letter now carries a REVOKED watermark, wherever it is
        // downloaded from -- see LetterRenderer::replaceStored().
        $letters = app(LetterRenderer::class);
        $letters->replaceStored($application, LetterRenderer::CERTIFICATION_DOC_TYPE, $letters->certification($application));

        return back()->with('status', "Certificate {$detail->reference_number} revoked.");
    }
}
