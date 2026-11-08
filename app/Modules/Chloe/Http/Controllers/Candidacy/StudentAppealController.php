<?php

namespace App\Modules\Chloe\Http\Controllers\Candidacy;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Services\CandidacyDocumentService;
use App\Modules\Chloe\Support\CandidacyAppealPresenter;
use App\Modules\Chloe\Support\CandidacyDepartmentResolver;
use App\Modules\Chloe\Support\CandidacyLiveVersion;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyReturnPath;
use App\Modules\Chloe\Support\StudentStatusReader;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student's side of the appeal: My Study Candidacy, the four-step form, resubmission, and the detail page every
 * role shares. Every action goes through CandidacyAppealService / CandidacyAppealPolicy -- nothing here decides a rule.
 */
class StudentAppealController extends Controller
{
    public function __construct(
        protected CandidacyAppealService $service,
        protected CandidacyDocumentService $documents,
    ) {}

    // ---------------------------------------------------------------- My Study Candidacy

    public function status(Request $request)
    {
        $user = $request->user();
        $candidacy = StudyCandidacy::where('student_id', $user->id)->first();

        return view('chloe::candidacy.wca.status', $this->statusData($user, $candidacy));
    }

    /** @return array<string, mixed> */
    protected function statusData($user, ?StudyCandidacy $candidacy): array
    {
        $appeals = CandidacyAppeal::where('student_id', $user->id)->with(['supervisorProfile', 'chairProfile', 'deanProfile'])->orderByDesc('id')->get();
        $problems = $this->service->eligibilityProblems($candidacy);
        $open = $appeals->first(fn ($a) => $a->isOpen());
        $draft = $appeals->first(fn ($a) => $a->isDraft());

        return [
            'candidacy' => $candidacy, 'appeals' => $appeals, 'problems' => $problems, 'open' => $open, 'draft' => $draft,
            'submittedCount' => $appeals->whereNotNull('appeal_no')->count(),
            'max' => (int) config('candidacy.max_extension_months'),
            'reminders' => $candidacy ? \App\Modules\Chloe\Models\CandidacyReminderLog::where('candidacy_id', $candidacy->id)->where('status', 'sent')->orderByDesc('sent_at')->get() : collect(),
            'liveVersion' => CandidacyLiveVersion::forStudent($user),
        ];
    }

    /** The live region only (the page swaps it in place when the version changes). */
    public function statusRegion(Request $request)
    {
        $user = $request->user();

        return view('chloe::candidacy.wca._status_region', $this->statusData($user, StudyCandidacy::where('student_id', $user->id)->first()));
    }

    // ---------------------------------------------------------------- the draft form

    public function start(Request $request)
    {
        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->first();
        $draft = CandidacyAppeal::where('student_id', $request->user()->id)->where('status', 'draft')->first();

        if (! $draft && ($problems = $this->service->eligibilityProblems($candidacy))) {
            return redirect()->route('candidacy.status')->withErrors(['appeal' => implode(' ', $problems)]);
        }

        return redirect()->route('candidacy.appeals.draft', ['step' => 1]);
    }

    public function draft(Request $request, int $step = 1)
    {
        $user = $request->user();
        $step = max(1, min(4, $step));
        $candidacy = StudyCandidacy::where('student_id', $user->id)->first();
        $appeal = CandidacyAppeal::where('student_id', $user->id)->where('status', 'draft')->first();

        if (! $appeal && ($problems = $this->service->eligibilityProblems($candidacy))) {
            return redirect()->route('candidacy.status')->withErrors(['appeal' => implode(' ', $problems)]);
        }

        // The first visit creates the (empty) draft so documents can be attached at step 3.
        $appeal ??= $this->service->saveDraft($user, []);

        return view('chloe::candidacy.wca.form', $this->formData($appeal, $candidacy, $step, 'draft'));
    }

    public function saveStep(Request $request, int $step)
    {
        $user = $request->user();
        $candidacy = StudyCandidacy::where('student_id', $user->id)->firstOrFail();
        $appeal = CandidacyAppeal::where('student_id', $user->id)->where('status', 'draft')->first();

        if (! $appeal) {
            if ($problems = $this->service->eligibilityProblems($candidacy)) {
                return redirect()->route('candidacy.status')->withErrors(['appeal' => implode(' ', $problems)]);
            }
            $appeal = $this->service->saveDraft($user, []);
        }

        $strict = $request->input('intent') === 'next';

        if ($step === 3 && $strict && ! $appeal->documents()->where('kind', CandidacyAppealDocument::KIND_OFFICIAL_FORM)->exists()) {
            return back()->withErrors(['official_form' => 'Upload the official appeal form (.xlsx) to continue.']);
        }

        $department = CandidacyDepartmentResolver::forCandidacy($candidacy);

        if ($step === 1 && ! CandidacyStaffDirectory::supervisorForStudent($user->id)) {
            return back()->withErrors(['supervisor' => CandidacyAppealService::NO_SUPERVISOR_MESSAGE]);
        }

        if ($step === 1 && ! $department) {
            return back()->withErrors(['department' => 'Your department could not be found in your student record, so the appeal cannot be submitted. Please contact the Centre for Graduate Studies.']);
        }

        if ($step === 1 || $step === 2) {
            $data = $request->validate($this->rules($step, $strict, $request, $candidacy->remainingAppealMonths(), $department?->id), $this->messages());
            $this->service->saveDraft($user, $this->cleanData($data, $step));
        }

        $target = match ($request->input('intent')) {
            'next' => min(4, $step + 1),
            'back' => max(1, $step - 1),
            default => $step,
        };

        return redirect()->route('candidacy.appeals.draft', ['step' => $target])
            ->with('wca_status', $request->input('intent') === 'save' ? 'Draft saved.' : null);
    }

    public function submit(Request $request)
    {
        $user = $request->user();
        $appeal = CandidacyAppeal::where('student_id', $user->id)->where('status', 'draft')->firstOrFail();
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm that you understand the appeal cannot be withdrawn.']);

        $submitted = $this->service->submit($user, $appeal);

        return redirect()->route('candidacy.appeals.show', $submitted)->with('wca_status', "Appeal {$submitted->appeal_no} was submitted.");
    }

    // ---------------------------------------------------------------- resubmit

    public function edit(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('edit', $appeal);

        if ($appeal->isDraft()) {
            return redirect()->route('candidacy.appeals.draft', ['step' => 1]);
        }

        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->first();

        return view('chloe::candidacy.wca.form', $this->formData($appeal, $candidacy, 2, 'resubmit'));
    }

    public function resubmit(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('edit', $appeal);
        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->firstOrFail();
        $data = $request->validate($this->rules(2, true, $request, $candidacy->remainingAppealMonths(), null), $this->messages());

        $this->service->resubmit($request->user(), $appeal, $this->cleanData($data, 2));

        return redirect()->route('candidacy.appeals.show', $appeal)->with('wca_status', "Appeal {$appeal->appeal_no} was resubmitted.");
    }

    // ---------------------------------------------------------------- documents

    public function uploadDocument(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('edit', $appeal);
        $request->validate(['kind' => ['required', Rule::in([CandidacyAppealDocument::KIND_OFFICIAL_FORM, CandidacyAppealDocument::KIND_SUPPORTING])], 'file' => ['required', 'file']],
            ['file.required' => 'Choose a file to upload.']);

        $this->documents->store($appeal, $request->file('file'), $request->input('kind'));

        return back()->with('wca_status', 'Document added.');
    }

    public function removeDocument(Request $request, CandidacyAppeal $appeal, CandidacyAppealDocument $document)
    {
        Gate::authorize('edit', $appeal);
        $this->documents->remove($appeal, $document);

        return back()->with('wca_status', 'Document removed.');
    }

    public function downloadDocument(Request $request, CandidacyAppeal $appeal, CandidacyAppealDocument $document): StreamedResponse
    {
        Gate::authorize('downloadDocument', $appeal);
        abort_unless($document->candidacy_appeal_id === $appeal->id && Storage::disk('local')->exists($document->stored_path), 404);

        // ?inline=1 is Core's in-app viewer (components/doc-link) -- only for
        // what a browser can render; anything else is still a download.
        if ($request->boolean('inline') && $mime = \App\Modules\Core\Support\FilePreview::inlineMime($document->original_name)) {
            return Storage::disk('local')->response($document->stored_path, $document->original_name, [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
            ], 'inline');
        }

        return Storage::disk('local')->download($document->stored_path, $document->original_name, ['Content-Type' => $document->mime]);
    }

    public function downloadMemo(CandidacyAppeal $appeal): StreamedResponse
    {
        Gate::authorize('downloadMemo', $appeal);
        abort_unless(Storage::disk('local')->exists($appeal->memo_path), 404);

        return Storage::disk('local')->download($appeal->memo_path, $appeal->memo_filename, ['Content-Type' => 'application/pdf']);
    }

    // ---------------------------------------------------------------- the detail page (every role) and live version

    public function show(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('view', $appeal);

        return view('chloe::candidacy.wca.show', $this->detailData($request, $appeal));
    }

    public function showRegion(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('view', $appeal);

        return view('chloe::candidacy.wca._detail_region', $this->detailData($request, $appeal));
    }

    /** @return array<string, mixed> everything the ONE appeal page shows, for the student, a named approver and CGS alike */
    public function detailData(Request $request, CandidacyAppeal $appeal): array
    {
        $appeal->load(['approvals', 'events', 'publications', 'documents', 'department', 'supervisorProfile', 'chairProfile', 'deanProfile', 'candidacy']);
        $user = $request->user();
        $canAct = $user->can('act', $appeal);
        $stage = $appeal->current_stage;
        $profile = $stage && $stage !== 'cgs' ? match ($stage) { 'supervisor' => $appeal->supervisorProfile, 'chair' => $appeal->chairProfile, default => $appeal->deanProfile } : CandidacyStaffDirectory::profileForUser($user);
        $candidacy = $appeal->candidacy;
        $months = (int) $appeal->requested_months;
        $isOwner = $appeal->student_id === $user->id;
        $default = $isOwner ? route('candidacy.status', [], false) : (CandidacyPortalQuery::isCgs($user) ? route('candidacy.cgs.operations', [], false) : route('candidacy.portal.queue', [], false));

        return [
            'appeal' => $appeal, 'identity' => $appeal->identity(), 'candidacy' => $candidacy,
            'isOwner' => $isOwner,
            'canEdit' => $user->can('edit', $appeal),
            'canMemo' => $user->can('downloadMemo', $appeal),
            'liveVersion' => CandidacyLiveVersion::forAppeal($appeal),
            'back' => CandidacyReturnPath::resolve($request, $default),
            'return' => CandidacyReturnPath::validate($request->query('return')),
            // "Your action" exists only while the appeal is waiting for THIS user
            'canAct' => $canAct, 'stage' => $stage,
            'needsSignature' => $canAct && in_array($stage, (array) config('candidacy.signature_required_stages'), true) && ! $profile?->hasSignature(),
            'returnReasons' => (array) config("candidacy.reasons.{$stage}.return", []),
            'rejectReasons' => (array) config("candidacy.reasons.{$stage}.reject", []),
            'showContext' => ! $isOwner,
            'studentStatus' => ! $isOwner && ($identity = $appeal->identity()) ? StudentStatusReader::for($identity) : null,
            'earlier' => $isOwner ? collect() : CandidacyAppeal::where('student_id', $appeal->student_id)->where('id', '!=', $appeal->id)->whereNotNull('appeal_no')->orderByDesc('id')->get(),
            'preview' => $candidacy && $months > 0 ? [
                'new_expiry' => $candidacy->candidacy_expiry_date->copy()->addMonthsNoOverflow($months),
                'allowance_after' => $candidacy->allowanceUsed() + $months,
            ] : null,
        ];
    }

    public function liveVersion(Request $request)
    {
        if ($id = $request->query('appeal')) {
            $appeal = CandidacyAppeal::findOrFail($id);
            Gate::authorize('view', $appeal);

            return response()->json(['version' => CandidacyLiveVersion::forAppeal($appeal)]);
        }

        abort_unless($request->user()->isStudent(), 403);

        return response()->json(['version' => CandidacyLiveVersion::forStudent($request->user())]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    protected function formData(CandidacyAppeal $appeal, ?StudyCandidacy $candidacy, int $step, string $mode): array
    {
        $appeal->load(['documents', 'publications', 'department']);
        $department = $candidacy ? CandidacyDepartmentResolver::forCandidacy($candidacy) : null;
        $returned = $mode === 'resubmit'
            ? $appeal->events()->whereIn('action', ['returned'])->get()->last()
            : null;

        return [
            'appeal' => $appeal, 'candidacy' => $candidacy, 'step' => $step, 'mode' => $mode,
            'department' => $department,
            'supervisor' => $appeal->student_id ? CandidacyStaffDirectory::supervisorForStudent($appeal->student_id) : null,
            'programme' => $appeal->student?->programme,
            'chair' => $department ? CandidacyStaffDirectory::chair($department) : null,
            'dean' => CandidacyStaffDirectory::dean(),
            'remaining' => $candidacy?->remainingAppealMonths() ?? 0,
            'phases' => CandidacyAppeal::phases(),
            'returnedEvent' => $returned,
            'officialForm' => $appeal->documents->where('kind', CandidacyAppealDocument::KIND_OFFICIAL_FORM)->sortByDesc('id')->first(),
            'supporting' => $appeal->documents->where('kind', CandidacyAppealDocument::KIND_SUPPORTING)->values(),
            'currentRound' => $this->documents->roundFor($appeal),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(int $step, bool $strict, Request $request, int $remaining, ?int $departmentId): array
    {
        $req = $strict ? 'required' : 'nullable';

        if ($step === 1) {
            return [
                // the department is not a choice: a posted value that is not the student's own is refused (a tampered form)
                'department_id' => ['nullable', 'integer', Rule::in([$departmentId])],
            ];
        }

        return [
            'phase' => [$req, Rule::in(array_keys(CandidacyAppeal::phases()))],
            'percent_complete' => [$req, 'integer', 'between:0,100'],
            'rcs_status' => [$req, Rule::in(['done', 'pending'])],
            'rcs_date' => [$strict ? 'required_if:rcs_status,done' : 'nullable', 'nullable', 'date', 'before_or_equal:today'],
            'rcs_expected_date' => [$strict ? 'required_if:rcs_status,pending' : 'nullable', 'nullable', 'date', 'after_or_equal:today'],
            'rcs_category' => ['nullable', 'string', 'max:255'],
            'gsc_extension' => ['nullable', 'boolean'],
            'vc_extension' => ['nullable', 'boolean'],
            'publications' => ['nullable', 'array', 'max:20'],
            'publications.*.title' => ['nullable', 'string', 'max:500'],
            'publications.*.venue' => ['nullable', 'string', 'max:255'],
            'publications.*.status' => ['nullable', 'string', 'max:40'],
            'publications.*.year' => ['nullable', 'integer', 'between:1950,2100'],
            'requested_months' => [$req, 'integer', 'min:1', 'max:'.max(1, $remaining)],
            'reason' => [$req, 'string', 'min:'.($strict ? 20 : 0), 'max:4000'],
            'declaration' => [$strict ? 'accepted' : 'nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'department_id.in' => 'Your department comes from your student record and cannot be changed.',
            'requested_months.max' => 'You can request at most your remaining allowance (:max month(s)).',
            'reason.min' => 'Please give a reason of at least 20 characters.',
            'declaration.accepted' => 'Accept the declaration to continue.',
            'rcs_date.required_if' => 'Enter the RCS date.',
            'rcs_expected_date.required_if' => 'Enter the expected RCS date.',
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function cleanData(array $data, int $step): array
    {
        if ($step !== 2) {
            return $data;
        }

        $data['publications'] ??= [];

        foreach (['gsc_extension', 'vc_extension', 'declaration'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }

        return $data;
    }
}
