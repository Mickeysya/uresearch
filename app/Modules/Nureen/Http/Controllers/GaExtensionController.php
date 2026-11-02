<?php

namespace App\Modules\Nureen\Http\Controllers;

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
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\Drafts;
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
        return view('nureen::ga_extension.form', [
            'student' => $request->user(),
            'requiredDocuments' => config('nureen.ga_extension.required_documents'),
            'additionalFields' => config('nureen.ga_extension.additional_fields'),
            'draft' => $draft,
            'detail' => $draft ? GaExtensionDetail::where('application_id', $draft->id)->first() : null,
            'attached' => $draft ? Drafts::attached($draft) : collect(),
            // On a fresh form, offer any draft already in progress.
            'otherDrafts' => $draft ? collect() : Drafts::of($request->user(), $this->moduleKey()),
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

        $rules = [
            'current_end_date' => ['nullable', 'date'],
            'requested_new_end_date' => ['nullable', 'date'],
            'reason_for_extension' => ['nullable', 'string', 'max:2000'],
            'draft_step' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
        $messages = [];

        foreach ($requiredDocuments as $field => $label) {
            $rules += $this->documentRules($field, $label, $application, $removing, required: false);
            $messages += DocumentStore::manyMessages($field);
        }

        foreach ($additionalFields as $key => $field) {
            // The same rules as submit, minus "required".
            $rules["additional_info.{$key}"] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $this->additionalFieldRules($field));
        }

        $data = $request->validate($rules, $messages);

        $application = DB::transaction(function () use ($request, $data, $application, $requiredDocuments, $additionalFields, $removing, $documents) {
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
            ]);

            Drafts::removeDocuments($application, $removing);

            foreach ($requiredDocuments as $field => $label) {
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
            ->with('status', 'Draft saved at '.now()->format('g:ia').'. Continue it any time from My Drafts.');
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
        // supervisor, so there has to be one -- same rule as
        // CertificationController::store().
        if (! $request->user()->supervisor_id) {
            return back()->with('error', 'No supervisor is assigned to your account. Please contact CGS.');
        }

        $requiredDocuments = config('nureen.ga_extension.required_documents');
        $additionalFields = config('nureen.ga_extension.additional_fields');
        $removing = $this->removing($request, $draft);

        $rules = [
            'current_end_date' => ['required', 'date'],
            'requested_new_end_date' => ['required', 'date', 'after:current_end_date'],
            'reason_for_extension' => ['required', 'string', 'min:20', 'max:2000'],
        ];

        $messages = [
            'requested_new_end_date.after' => 'The new end date must be later than the current one.',
            'reason_for_extension.min' => 'Please give CGS at least a sentence or two of justification.',
        ];

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
        foreach ($additionalFields as $key => $field) {
            $rules["additional_info.{$key}"] = $this->additionalFieldRules($field);
        }

        $data = $request->validate($rules, $messages);

        $application = DB::transaction(function () use ($request, $data, $draft, $removing, $requiredDocuments, $additionalFields, $engine, $documents) {
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
            ]);

            Drafts::removeDocuments($application, $removing);

            foreach ($requiredDocuments as $field => $label) {
                if ($files = $request->file($field)) {
                    $documents->attachMany($application, $files, $label);
                }
            }

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "GA Extension application #{$application->id} submitted.");
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

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
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
