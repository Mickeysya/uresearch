<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Nureen\Support\SubmissionNotifier;
use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionCc;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionInformational;
use App\Modules\Nureen\Support\SupervisorCheck;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\Drafts;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class GaExtensionController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'ga_extension';
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
        $student = $request->user()->loadMissing('supervisor');

        return view('nureen::ga_extension.form', [
            'student' => $student,
            'supervisorProblem' => SupervisorCheck::problemFor($student),
            'requiredDocuments' => config('nureen.ga_extension.required_documents'),
            'stageGateDocuments' => config('nureen.ga_extension.stage_gate_documents'),
            'applicantFields' => $this->applicantFields(),
            'publicationOptions' => config('nureen.ga_extension.publications'),
            'declaration' => config('nureen.ga_extension.declaration'),
            'rules' => config('nureen.ga_extension.rules'),
            'allowedLevels' => $this->allowedLevels(),
            'additionalFields' => config('nureen.ga_extension.additional_fields'),
            'draft' => $draft,
            'detail' => $draft ? GaExtensionDetail::where('application_id', $draft->id)->first() : null,
            'attached' => $draft ? Drafts::attached($draft) : collect(),
            // On a fresh form, offer any draft already in progress.
            'otherDrafts' => $draft ? collect() : Drafts::of($request->user(), $this->moduleKey()),
            // The generated form of every application already submitted --
            // Core's application page has no slot for a module's download
            // yet (SHARED_CHANGES.md), so the student finds it here.
            'submitted' => $draft ? collect() : Application::query()
                ->where('student_id', $student->id)
                ->where('module_type', $this->moduleKey())
                ->where('status', '!=', Application::STATUS_DRAFT)
                ->latest('id')
                ->limit(10)
                ->get(),
            // "Download form (PDF)" saved the draft first; start the download.
            'downloadForm' => session('download_form') && $draft ? route('ga-extension.form-pdf', $draft) : null,
        ]);
    }

    /**
     * "Save draft": creates the draft on the first save, updates it after.
     * Light validation only -- types, lengths, file types and sizes, never
     * "required" -- so a half-finished form always saves. Files are attached
     * through DocumentStore straight away, so they survive between
     * sessions; ticked "Remove" boxes take files off.
     */
    public function saveDraft(Request $request, DocumentStore $documents, ?Application $application = null): RedirectResponse
    {
        if ($application) {
            Drafts::ownedBy($request->user(), $application, $this->moduleKey());
        }

        $requiredDocuments = config('nureen.ga_extension.required_documents');
        $additionalFields = config('nureen.ga_extension.additional_fields');
        $removing = $this->removing($request, $application);

        $detail = $application ? GaExtensionDetail::where('application_id', $application->id)->first() : null;
        $this->normalisePublications($request);

        $rules = [
            'current_end_date' => ['nullable', 'date'],
            'requested_new_end_date' => ['nullable', 'date'],
            'reason_for_extension' => ['nullable', 'string', 'max:2000'],
            'draft_step' => ['nullable', 'integer', 'min:0', 'max:20'],
            'declaration' => ['nullable', 'boolean'],
            'download_form' => ['nullable', 'boolean'],
        ] + $this->applicantRules(required: false, existing: $detail) + $this->publicationRules(required: false);
        $messages = $this->publicationMessages();

        foreach ($requiredDocuments + config('nureen.ga_extension.stage_gate_documents') as $field => $label) {
            $rules += $this->documentRules($field, $label, $application, $removing, required: false);
            $messages += DocumentStore::manyMessages($field);
        }

        foreach ($additionalFields as $key => $field) {
            // The same rules as submit, minus "required".
            $rules["additional_info.{$key}"] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $this->additionalFieldRules($field));
        }

        $data = $request->validate($rules, $messages);

        $application = DB::transaction(function () use ($request, $data, $application, $detail, $requiredDocuments, $additionalFields, $removing, $documents) {
            $application ??= Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            GaExtensionDetail::updateOrCreate(['application_id' => $application->id], [
                'current_end_date' => $data['current_end_date'] ?? null,
                'requested_new_end_date' => $data['requested_new_end_date'] ?? null,
                'reason_for_extension' => $data['reason_for_extension'] ?? null,
                'additional_info' => $additionalFields === [] ? null : ($data['additional_info'] ?? []),
                'draft_step' => $data['draft_step'] ?? null,
                'publications' => $data['publications'] ?? [],
                'declaration_accepted_at' => ! empty($data['declaration']) ? ($detail?->declaration_accepted_at ?? now()) : null,
            ] + $this->applicantValues($data, $detail));

            Drafts::removeDocuments($application, $removing);

            foreach ($requiredDocuments + config('nureen.ga_extension.stage_gate_documents') as $field => $label) {
                if ($files = $request->file($field)) {
                    $documents->attachMany($application, $files, $label);
                }
            }

            // "Last saved" is the draft row's updated_at.
            $application->touch();

            return $application;
        });

        return redirect()
            ->route('ga-extension.draft.edit', $application)
            ->with('status', 'Draft saved at '.now()->format('g:ia').'. Continue it any time from My Drafts.')
            ->with('download_form', ! empty($data['download_form']));
    }

    /** Delete a draft: its stored files, its detail row and the application row. */
    public function destroyDraft(Request $request, Application $application): RedirectResponse
    {
        Drafts::delete(Drafts::ownedBy($request->user(), $application, $this->moduleKey()), GaExtensionDetail::class);

        return redirect()->route('nureen.drafts')->with('status', 'Draft deleted.');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        // Submitting a saved draft reuses it rather than creating a second
        // application -- only the student's own, and only while still a draft.
        $draft = $request->filled('draft_id')
            ? Drafts::ownedBy($request->user(), Application::findOrFail((int) $request->input('draft_id')), $this->moduleKey())
            : null;

        // The first stage is addressed to the student's own named
        // supervisor, so there has to be one who can act -- same rule as
        // CertificationController::store(); see Support\SupervisorCheck.
        if ($problem = SupervisorCheck::problemFor($request->user())) {
            return back()->withInput()->with('error', $problem);
        }

        $requiredDocuments = config('nureen.ga_extension.required_documents');
        $additionalFields = config('nureen.ga_extension.additional_fields');
        $removing = $this->removing($request, $draft);
        $existing = $draft ? GaExtensionDetail::where('application_id', $draft->id)->first() : null;
        $this->normalisePublications($request);

        $rules = [
            'current_end_date' => ['required', 'date'],
            'requested_new_end_date' => ['required', 'date', 'after:current_end_date'],
            'reason_for_extension' => ['required', 'string', 'min:20', 'max:2000'],
            'declaration' => ['accepted'],
        ] + $this->applicantRules(required: true, existing: $existing) + $this->publicationRules(required: true);

        // config('nureen.ga_extension.allowed_levels'): the July 2026 form
        // offers MSc and PhD, the Postgraduate Handbook says PhD only. The
        // one handbook rule that blocks -- and only on submit, so a draft
        // still saves.
        $rules['level_of_study'][] = function (string $attribute, $value, \Closure $fail) {
            if (filled($value) && ! array_key_exists($value, $this->allowedLevels())) {
                $fail('GA extension is open to '.implode(' and ', $this->allowedLevels()).' students only. Contact CGS if you think this is wrong.');
            }
        };

        $messages = [
            'requested_new_end_date.after' => 'The new end date must be later than the current one.',
            'reason_for_extension.min' => 'Please give CGS at least a sentence or two of justification.',
            'declaration.accepted' => 'Please tick the declaration to confirm the information is true and correct.',
            'visa_expiry_date.required_if' => 'Enter your visa expiry date.',
        ] + $this->publicationMessages();

        // The scope calls for document completeness validation before an
        // application may reach CGS, so each required type is enforced
        // separately rather than as one undifferentiated upload -- see
        // DocumentStore::manyRules()/manyMessages() for the shared
        // count/size/type limits and their matching messages. A type the
        // draft already has a file for is satisfied by that file.
        foreach ($requiredDocuments as $field => $label) {
            $rules += $this->documentRules($field, $label, $draft, $removing, required: true);
            $messages["{$field}.required"] = "Attach at least one file for \"{$label}\".";
            $messages += DocumentStore::manyMessages($field);
        }

        // Provisional fields from config('nureen.ga_extension.additional_fields')
        // -- empty today, so this adds nothing until the real CGS form is
        // confirmed and a field is declared there.
        foreach (config('nureen.ga_extension.stage_gate_documents') as $field => $label) {
            $rules += $this->documentRules($field, $label, $draft, $removing, required: false);
            $messages += DocumentStore::manyMessages($field);
        }

        foreach ($additionalFields as $key => $field) {
            $rules["additional_info.{$key}"] = $this->additionalFieldRules($field);
        }

        $data = $request->validate($rules, $messages);

        // The one rule of the form's Terms and Conditions that is absolute:
        // "Backdated appointment ... is not allowed". The rest only warn,
        // on the approver's System checks (Support\GaExtensionRules).
        if (\Illuminate\Support\Carbon::parse($data['current_end_date'])->addDay()->lt(today())) {
            return back()->withInput()->withErrors([
                'current_end_date' => 'The extension would start before today. Backdated GA appointments are not allowed; contact CGS.',
            ]);
        }

        $application = DB::transaction(function () use ($request, $data, $draft, $existing, $removing, $requiredDocuments, $additionalFields, $engine, $documents) {
            if ($draft) {
                // Locked and re-checked, so a double-click can never submit
                // the same draft twice.
                $application = Application::whereKey($draft->id)->lockForUpdate()->first();
                abort_unless($application?->status === Application::STATUS_DRAFT, 409, 'This draft has already been submitted.');
            } else {
                $application = Application::create([
                    'student_id' => $request->user()->id,
                    'submitted_by_id' => $request->user()->id,
                    'module_type' => $this->moduleKey(),
                    'status' => Application::STATUS_DRAFT,
                ]);
            }

            GaExtensionDetail::updateOrCreate(['application_id' => $application->id], [
                // Snapshotted like Certification's supervisor_id: if the
                // student's supervisor changes later, this application
                // stays with whoever it was addressed to -- at SUBMISSION,
                // not when the draft was first saved.
                'supervisor_id' => $request->user()->supervisor_id,
                'current_end_date' => $data['current_end_date'],
                'requested_new_end_date' => $data['requested_new_end_date'],
                'reason_for_extension' => $data['reason_for_extension'],
                'additional_info' => $additionalFields === [] ? null : ($data['additional_info'] ?? []),
                // Snapshotted now, not re-read later -- see
                // GaExtensionWorkflow::stages(). A later change to this
                // config must never alter which chain an already-submitted
                // application is running.
                'final_approver_snapshot' => config('nureen.ga_extension.final_approver'),
                'draft_step' => null,
                'publications' => $data['publications'] ?? [],
                'declaration_accepted_at' => now(),
            ] + $this->applicantValues($data, $existing));

            Drafts::removeDocuments($application, $removing);

            foreach ($requiredDocuments + config('nureen.ga_extension.stage_gate_documents') as $field => $label) {
                if ($files = $request->file($field)) {
                    $documents->attachMany($application, $files, $label);
                }
            }

            return $engine->submit($application);
        });

        // Whoever acts first is told now -- Core's engine only notifies a
        // stage when an earlier one decides. See Support\SubmissionNotifier.
        app(SubmissionNotifier::class)->notify($application);

        return redirect()
            ->route('applications.index')
            ->with('status', "GA Extension application #{$application->id} submitted.");
    }

    /**
     * The generated GA Extension Form (UTP/CGS/001-A2), as it stands now --
     * see LetterRenderer::gaExtensionForm(). For the student who owns it
     * (a draft included) and for the approvers of a submitted one.
     */
    public function formPdf(Request $request, Application $application, LetterRenderer $letters)
    {
        return $this->formResponse($request, $application, $letters, 'attachment');
    }

    /** The same form, inline -- the preview overlay's source. Same check as formPdf(). */
    public function formPdfView(Request $request, Application $application, LetterRenderer $letters)
    {
        return $this->formResponse($request, $application, $letters, 'inline');
    }

    protected function formResponse(Request $request, Application $application, LetterRenderer $letters, string $disposition)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($this->canViewForm($request->user(), $application), 403, 'This application is not yours to view.');

        $filename = 'GA_Extension_Form_'.($application->student?->matric_no ?: 'application').'_'.$application->id.'.pdf';

        return response($letters->gaExtensionForm($application, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }

    /**
     * The owner; and once submitted, the supervisor it is addressed to (or
     * who endorsed it, if that has since changed) and anyone holding the
     * role of another stage on this application's own chain.
     */
    protected function canViewForm(User $user, Application $application): bool
    {
        if ($application->student_id === $user->id) {
            return true;
        }

        if ($application->status === Application::STATUS_DRAFT) {
            return false;
        }

        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        if ($detail?->supervisor_id === $user->id
            || ApprovalHistory::where('application_id', $application->id)->where('stage_key', 'supervisor')->where('approver_id', $user->id)->exists()) {
            return true;
        }

        foreach (app(WorkflowEngine::class)->stagesFor($application) as $stage) {
            if ($stage->key !== 'supervisor' && $stage->role === $user->role) {
                return true;
            }
        }

        return false;
    }

    /**
     * Section J, "CGS use remarks": saved by CGS staff while the
     * application is at their stage. Not a decision -- approving or
     * rejecting is still the decision form below it.
     */
    public function saveCgsRemarks(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        abort_unless($stage?->key === 'cgs_verify' && $request->user()->role === $stage->role, 403, 'Section J is filled at the CGS stage.');

        $fields = config('nureen.ga_extension.cgs_remarks_fields');
        $rules = [];
        foreach ($fields as $key => $field) {
            $rules["cgs_remarks.{$key}"] = ['nullable', ($field['type'] ?? 'text') === 'date' ? 'date' : 'string', 'max:255'];
        }

        $data = $request->validate($rules);

        GaExtensionDetail::where('application_id', $application->id)->firstOrFail()->update([
            'cgs_remarks' => array_intersect_key($data['cgs_remarks'] ?? [], $fields),
            'cgs_remarks_by_id' => $request->user()->id,
            'cgs_remarks_at' => now(),
        ]);

        return back()->with('status', "Section J saved for application #{$application->id}.");
    }

    /** config('nureen.ga_extension.allowed_levels') as key => label, e.g. ['phd' => 'PhD']. */
    protected function allowedLevels(): array
    {
        $options = config('nureen.ga_extension.applicant_fields.level_of_study.options');

        return array_intersect_key($options, array_flip(config('nureen.ga_extension.allowed_levels')));
    }

    /** config('nureen.ga_extension.applicant_fields'), less the bank fields when they are not collected. */
    protected function applicantFields(): array
    {
        $collectBank = (bool) config('nureen.ga_extension.collect_bank_details');

        return array_filter(config('nureen.ga_extension.applicant_fields'), fn (array $field) => $collectBank || empty($field['bank']));
    }

    /**
     * Section B's rules, built from applicantFields(). On a draft nothing is
     * required. A sensitive field already on file may be left blank.
     */
    protected function applicantRules(bool $required, ?GaExtensionDetail $existing): array
    {
        $rules = [];

        foreach ($this->applicantFields() as $key => $field) {
            $type = $field['type'] ?? 'text';
            $onFile = ! empty($field['sensitive']) && filled($existing?->{$key});

            $presence = match (true) {
                ! $required => 'nullable',
                isset($field['required_if']) => "required_if:{$field['required_if']},1",
                ($field['required'] ?? false) && ! $onFile => 'required',
                default => 'nullable',
            };

            $rules[$key] = array_values(array_filter([
                $presence,
                ...match ($type) {
                    'select', 'radio' => ['string', 'in:'.implode(',', array_keys($field['options']))],
                    'date' => ['date', $key === 'admission_date' ? 'before_or_equal:today' : null],
                    'yesno' => ['boolean'],
                    'email' => ['email', 'max:'.($field['max'] ?? 150)],
                    'tel' => ['string', 'max:'.($field['max'] ?? 20), 'regex:/^\+?[0-9][0-9 ()\-]{6,}$/'],
                    default => ['string', 'max:'.($field['max'] ?? 255),
                        ! empty($field['sensitive']) ? 'regex:/^[A-Za-z0-9][A-Za-z0-9\- ]*$/' : null],
                },
            ]));
        }

        return $rules;
    }

    /**
     * What to write to the detail row for Section B. Profile fields are not
     * here at all -- they are read from the user when shown. A blank
     * sensitive field keeps what is on file; the visa date is cleared when
     * the student says they hold no visa.
     */
    protected function applicantValues(array $data, ?GaExtensionDetail $existing): array
    {
        $values = [];

        foreach ($this->applicantFields() as $key => $field) {
            if (! empty($field['sensitive']) && blank($data[$key] ?? null)) {
                continue;
            }

            $values[$key] = $data[$key] ?? null;
        }

        if (array_key_exists('holds_visa', $values) && $values['holds_visa'] !== null && ! $values['holds_visa']) {
            $values['visa_expiry_date'] = null;
        }

        return $values;
    }

    /**
     * Section C arrives as rows from the add/remove list; rows left wholly
     * empty are dropped and the rest re-indexed before validation, so an
     * untouched blank row never fails or gets stored.
     */
    protected function normalisePublications(Request $request): void
    {
        $rows = $request->input('publications');

        if (! is_array($rows)) {
            $request->merge(['publications' => []]);

            return;
        }

        $request->merge(['publications' => array_values(array_filter($rows,
            fn ($row) => is_array($row) && array_filter($row, fn ($value) => is_string($value) && trim($value) !== '') !== []))]);
    }

    protected function publicationRules(bool $required): array
    {
        $config = config('nureen.ga_extension.publications');
        $need = $required ? 'required' : 'nullable';

        return [
            'publications' => ['array', 'max:'.$config['max_rows']],
            'publications.*.type' => [$need, 'in:'.implode(',', array_keys($config['types']))],
            'publications.*.status' => [$need, 'in:'.implode(',', array_keys($config['statuses']))],
            'publications.*.authors' => [$need, 'string', 'max:500'],
            'publications.*.year' => [$need, 'integer', 'between:1950,'.(now()->year + 1)],
            'publications.*.title' => [$need, 'string', 'max:300'],
            'publications.*.venue' => [$need, 'string', 'max:200'],
            'publications.*.volume' => ['nullable', 'string', 'max:30'],
            'publications.*.pages' => ['nullable', 'string', 'max:30'],
            'publications.*.doi' => ['nullable', 'string', 'max:100'],
            'publications.*.issn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{4}-?[0-9]{3}[0-9Xx]$/'],
            'publications.*.database' => ['nullable', 'in:'.implode(',', array_keys($config['databases']))],
            'publications.*.quartile' => ['nullable', 'in:'.implode(',', array_keys($config['quartiles']))],
            'publications.*.impact_factor' => ['nullable', 'numeric', 'between:0,1000'],
        ];
    }

    protected function publicationMessages(): array
    {
        return [
            'publications.max' => 'List at most :max publications.',
            'publications.*.*.required' => 'Fill this in for every publication you list, or remove the row.',
            'publications.*.issn.regex' => 'An ISSN looks like 1234-5678.',
        ];
    }

    /**
     * One document type's rules. Required on submit unless the draft
     * already has a file of that type (after removals); never required on
     * a draft save. The 5-file limit counts the files already attached.
     *
     * @param  array<int, int>  $removing
     */
    protected function documentRules(string $field, string $label, ?Application $draft, array $removing, bool $required): array
    {
        $already = $draft ? (Drafts::attached($draft, $removing)->get($label)?->count() ?? 0) : 0;
        $rules = DocumentStore::manyRules($field, $required && $already === 0);
        $rules[$field][] = function (string $attribute, $value, \Closure $fail) use ($already) {
            if ($already + count((array) $value) > DocumentStore::MULTI_MAX_FILES) {
                $fail('You may attach at most '.DocumentStore::MULTI_MAX_FILES." files of this type ({$already} already attached).");
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

    public function queue(Request $request, WorkflowEngine $engine, ClientCharter $charter)
    {
        // Same reasoning as CertificationController::queue(): the engine's
        // queue is role-scoped, not person-scoped, so the 'supervisor'
        // stage is narrowed to the one supervisor this application names
        // (GaExtensionDetail::supervisor_id). Every stage is also ordered
        // by when each row reached it, so anything past its Client Charter
        // due date sits at the top -- see ClientCharter::orderByStageEntry().
        $queue = $this->queueFor($request, $engine, ['documents', 'history'],
            function ($query, Stage $stage) use ($request, $charter) {
                $charter->orderByStageEntry($query);

                if ($stage->key !== 'supervisor') {
                    return;
                }

                $query->whereIn('id', GaExtensionDetail::query()
                    ->where('supervisor_id', $request->user()->id)
                    ->select('application_id'));
            });

        $details = GaExtensionDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('cgsRemarksBy')
            ->get()
            ->keyBy('application_id');

        return view('nureen::ga_extension.queue', $queue + [
            'details' => $details,
            'clocks' => $queue['applications']->mapWithKeys(fn (Application $a) => [$a->id => $charter->stageClock($a)]),
            // Only CGS Staff, at the cgs_verify stage, may send an
            // incomplete application back to the supervisor rather than
            // rejecting it outright -- see returnToSupervisor().
            'canReturn' => $queue['stage']->key === 'cgs_verify',
        ]);
    }

    /**
     * Sends an application back to the supervisor instead of rejecting it,
     * from CGS Staff's desk -- a missing or wrong document does not need to
     * end the application, only go back one stage for a fix.
     *
     * Not a decide()-time check: the route is open to the whole chain and
     * WorkflowEngine::returnTo() refuses it anywhere but the cgs_verify
     * stage, the same way every other authorisation check in this app
     * belongs to the engine rather than to a middleware list. See
     * Hani\Http\Controllers\ExaminerNominationController::returnToDepartment()
     * for the pattern this follows.
     */
    public function returnToSupervisor(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Say what is missing or needs changing — the supervisor is being asked to review it again.',
        ]);

        try {
            $engine->returnTo($application, $request->user(), 'supervisor', $data['remarks']);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application is not at a stage you can send back.');
        }

        return back()->with(
            'status',
            "Application #{$application->id} sent back to the supervisor. They have been notified."
        );
    }

    /**
     * Overrides the trait's decide(): a 'supervisor'-stage decision must
     * come from the supervisor this application names, and the final
     * decision of this chain --
     * wherever it lands, see GaExtensionWorkflow::stages() -- also copies
     * the endorsing supervisor and, in 'cgs' mode, informs the Senior
     * Director who no longer has a stage of their own. See
     * DecidesOneAtATime on GaExtensionWorkflow.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        // A rejection needs a reason, as in Supervision; the decision bar
        // asks for it in the browser too (components/decision-bar).
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => [$request->input('decision') === 'reject' ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Say why this request is being rejected — the student is owed a reason.',
        ]);

        $stage = $engine->currentStage($application);
        $mode = app(GaExtensionWorkflow::class)->modeFor($application);

        // The engine only checks the role. At the 'supervisor' stage that
        // is every supervisor in the university; this application is
        // addressed to one of them -- see queue().
        if ($stage?->key === 'supervisor'
            && GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id') !== $request->user()->id) {
            abort(403, 'This request was not addressed to you.');
        }

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($stage && $decided->status !== Application::STATUS_PENDING) {
            $this->notifyFinalDecision($decided, $stage, $data['decision'] === 'approve', $mode);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    protected function notifyFinalDecision(Application $application, Stage $stage, bool $approved, string $mode): void
    {
        // The person who actually endorsed at the 'supervisor' stage, not
        // whoever currently holds the Supervisor role for this student --
        // the two can differ if the supervisor has since changed.
        $endorsement = ApprovalHistory::where('application_id', $application->id)
            ->where('stage_key', 'supervisor')
            ->first();

        if ($endorsement) {
            User::find($endorsement->approver_id)?->notify(new GaExtensionFinalDecisionCc($application, $stage, $approved));
        }

        if ($mode === 'cgs') {
            foreach (User::where('role', Role::SENIOR_DIRECTOR_CGS)->get() as $director) {
                $director->notify(new GaExtensionFinalDecisionInformational($application, $stage, $approved));
            }
        }
    }

    /** @return array<int, string> */
    protected function additionalFieldRules(array $field): array
    {
        $type = $field['type'] ?? 'text';

        return array_filter([
            ($field['required'] ?? false) ? 'required' : 'nullable',
            match ($type) {
                'date' => 'date',
                default => 'string',
            },
            $type === 'text' ? 'max:255' : ($type === 'textarea' ? 'max:2000' : null),
        ]);
    }
}
