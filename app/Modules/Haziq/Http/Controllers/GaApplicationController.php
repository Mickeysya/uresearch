<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Http\Controllers\Concerns\HandlesApplicationCorrections;
use App\Modules\Haziq\Models\AcademicQualification;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\Publication;
use App\Modules\Haziq\Models\TermsAcceptance;
use App\Modules\Haziq\Models\WorkExperience;
use App\Modules\Haziq\Notifications\ApplicationCorrectionResubmitted;
use App\Modules\Haziq\Notifications\ApplicationRejected;
use App\Modules\Haziq\Notifications\ApplicationSubmitted;
use App\Modules\Haziq\Notifications\GaInterviewFailed;
use App\Modules\Haziq\Notifications\GaInterviewPassed;
use App\Modules\Haziq\Notifications\GaInterviewScheduled;
use App\Modules\Haziq\Notifications\LetterIssued;
use App\Modules\Haziq\Notifications\StageAwaitingReview;
use App\Modules\Haziq\Support\DocumentCompleteness;
use App\Modules\Haziq\Support\Roles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\UnauthorizedException;

class GaApplicationController extends Controller
{
    use ApprovesApplications, HandlesApplicationCorrections;

    protected function moduleKey(): string
    {
        return 'ga_application';
    }

    protected function documentTypes(): array
    {
        return [
            'resume' => 'Resume/CV',
            'academic_transcript' => 'Academic Transcript',
            'visa_or_ic' => 'Visa / IC',
        ];
    }

    /**
     * Mirrors GraApplicationController::sectionLabels() — confirmed
     * explicitly 2026-11-03: "each level boleh verify form, bagitahu mana
     * salah macam biasa ah." Section D/E/F are the same repeatable tables
     * GRA uses (same shared gra_ga_student_profiles), so they're flagged
     * whole-section here too, same reasoning as GRA's own docblock.
     */
    protected function sectionLabels(): array
    {
        return [
            'section_b' => 'Section B — GA Details',
            'section_c' => 'Section C — Candidate Details',
            'section_d' => 'Section D — Academic Qualification',
            'section_e' => 'Section E — Working Experience',
            'section_f' => 'Section F — Publications',
        ];
    }

    protected function sectionFieldLabels(): array
    {
        return [
            'section_b' => [
                'department_unit' => 'Department / Unit',
                'purpose' => 'Purpose',
                'proposed_hours_per_week' => 'Proposed Hours per Week',
                'service_start_date' => 'Service Start Date',
            ],
            'section_c' => [
                'level_of_study' => 'Level of Study',
                'programme' => 'Programme',
                'admission_date' => 'Admission Date',
            ],
        ];
    }

    /**
     * Every approver can flag a correction on any GA application at any
     * time it's still pending, not just the stage whose turn it
     * currently is — confirmed explicitly 2026-11-03, same reasoning as
     * GRA's own canStillFlag(). But "at any time it's pending" was never
     * actually the whole rule even there: once THIS viewer's own stage
     * has already recorded its decision, they specifically can no
     * longer flag anything, even while the application is still pending
     * somewhere later in the chain -- confirmed explicitly 2026-11-10,
     * matching GRA exactly: "dah approve or verify mana leh nak flag
     * pape dah... nak buat before verify or approve." This method's own
     * docblock already claimed parity with GRA's; the implementation
     * never actually had the per-stage check GRA's does, until now.
     */
    protected function canStillFlag(User $user, Application $application): bool
    {
        if ($application->status !== Application::STATUS_PENDING) {
            return false;
        }

        $stageKey = $this->viewerStageKeyFor($user);

        if ($stageKey === null) {
            return true;
        }

        return ! ApprovalHistory::where('application_id', $application->id)
            ->where('stage_key', $stageKey)
            ->exists();
    }

    /** Same as GraApplicationController::viewerStageKeyFor() -- which of this chain's stages (if any) this user's role sits at. */
    protected function viewerStageKeyFor(User $user): ?string
    {
        $stages = (new \App\Modules\Haziq\Workflows\GaApplicationWorkflow())->stages();

        foreach ($stages as $stage) {
            if ($stage->role === $user->role) {
                return $stage->key;
            }
        }

        if ($user->isCgs()) {
            foreach ($stages as $stage) {
                if ($stage->role === Role::NON_EXEC_CGS) {
                    return $stage->key;
                }
            }
        }

        return null;
    }

    /**
     * Mirrors GraApplicationController::hasAcceptedTerms() — a separate
     * scheme from GRA's own, confirmed explicitly 2026-11-03: "ga is
     * different from gra." One row per student per scheme, covers every
     * GA application that student submits afterwards.
     */
    protected function hasAcceptedTerms(User $user): bool
    {
        return TermsAcceptance::where('user_id', $user->id)->where('scheme', TermsAcceptance::GA)->exists();
    }

    public function showTerms()
    {
        return view('haziq::ga_application.terms');
    }

    public function acceptTerms(Request $request): RedirectResponse
    {
        $request->validate(['agree' => ['required', 'accepted']]);

        TermsAcceptance::firstOrCreate(
            ['user_id' => $request->user()->id, 'scheme' => TermsAcceptance::GA],
            ['accepted_at' => now()]
        );

        return redirect()->route('ga-application.create');
    }

    /**
     * Mirrors GraApplicationController::create() — terms gate, then a
     * saved draft (if any) pre-fills the form; a second 'new' application
     * can't be started while one is already in flight.
     */
    public function create(Request $request)
    {
        if (! $this->hasAcceptedTerms($request->user())) {
            return redirect()->route('ga-application.terms');
        }

        if ($existing = $this->activeApplication($request)) {
            return redirect()->route('ga-application.mine', $existing)
                ->with('status', 'You already have a GA application in progress — here it is.');
        }

        $draft = $this->draftApplication($request);
        $draftDetail = $draft ? GaApplicationDetail::where('application_id', $draft->id)->first() : null;
        $profile = $draft ? GraGaStudentProfile::find($draftDetail->profile_id) : GraGaStudentProfile::where('user_id', $request->user()->id)->first();

        return view('haziq::ga_application.form', [
            'profile' => $profile,
            'academics' => $profile ? AcademicQualification::where('profile_id', $profile->id)->get() : collect(),
            'experiences' => $profile ? WorkExperience::where('profile_id', $profile->id)->get() : collect(),
            'publications' => $profile ? Publication::where('profile_id', $profile->id)->get() : collect(),
            'draft' => $draft,
            'draftDetail' => $draftDetail,
            'draftDocuments' => $draft ? ApplicationDocument::where('application_id', $draft->id)->get() : collect(),
        ]);
    }

    /** The student's own unsubmitted GA application, if any. */
    protected function draftApplication(Request $request): ?Application
    {
        return GaApplicationDetail::whereHas('application', fn ($q) => $q
            ->where('student_id', $request->user()->id)
            ->where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_DRAFT))
            ->with('application')
            ->latest('id')
            ->first()
            ?->application;
    }

    /**
     * "My Drafts" (Nureen\Support\Drafts::of()) Continue link. There is at
     * most one draft per student (store() either updates it or creates the
     * only one, activeApplication() blocks a second), so this just
     * re-enters create() — which already finds and pre-fills it — once
     * ownership of $application itself is confirmed.
     */
    public function editDraft(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403, 'This draft is not yours.');
        abort_unless($application->status === Application::STATUS_DRAFT, 404, 'This application has already been submitted.');

        return $this->create($request);
    }

    /** "My Drafts" Delete — the draft, its detail row and its attached files, gone completely. */
    public function destroyDraft(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403, 'This draft is not yours.');
        abort_unless($application->status === Application::STATUS_DRAFT, 404, 'This application has already been submitted.');

        DB::transaction(function () use ($application) {
            $application->documents->each(function (ApplicationDocument $document) {
                Storage::disk('local')->delete($document->path);
                $document->delete();
            });

            GaApplicationDetail::where('application_id', $application->id)->delete();
            $application->delete();
        });

        return redirect()->route('nureen.drafts')->with('status', 'Draft deleted.');
    }

    /** The student's current GA application still in flight (pending or approved), if any. */
    protected function activeApplication(Request $request): ?Application
    {
        return Application::where('student_id', $request->user()->id)
            ->where('module_type', $this->moduleKey())
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->latest('id')
            ->first();
    }

    /**
     * Mirrors GraApplicationController::store() — Save Draft (keeps
     * status DRAFT, never calls engine->submit()) and Submit Application
     * behind the same route, with Section D/E/F repeatables now saved
     * too (the old version never did). Documents are never required to
     * Submit, same as GRA's own store() — missingRequiredDocuments() only
     * folds a note into the confirmation message now.
     */
    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        abort_unless($this->hasAcceptedTerms($request->user()), 403, 'Accept the GA Scheme Terms and Conditions first.');

        if ($existing = $this->activeApplication($request)) {
            return redirect()->route('ga-application.mine', $existing)
                ->with('status', 'You already have a GA application in progress.');
        }

        $action = $request->input('action') === 'save_draft' ? 'save_draft' : 'submit';
        $draft = $this->draftApplication($request);

        $data = $request->validate([
            'department_unit' => ['nullable', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'proposed_hours_per_week' => ['nullable', 'integer', 'min:1', 'max:21'],
            'service_start_date' => ['required', 'date'],
            'service_end_date' => ['required', 'date', 'after:service_start_date'],
            'proposed_allowance' => ['required', 'numeric', 'min:0'],

            'level_of_study' => ['required', 'in:master,phd'],
            'programme' => ['required', 'string', 'max:150'],
            'admission_date' => ['required', 'date'],

            'academic.*.level_of_study' => ['nullable', 'string', 'max:100'],
            'academic.*.institution' => ['nullable', 'string', 'max:150'],
            'academic.*.programme' => ['nullable', 'string', 'max:150'],
            'academic.*.result' => ['nullable', 'string', 'max:100'],
            'academic.*.year_from' => ['nullable', 'string', 'max:4'],
            'academic.*.year_to' => ['nullable', 'string', 'max:4'],

            'experience.*.company_name' => ['nullable', 'string', 'max:150'],
            'experience.*.designation' => ['nullable', 'string', 'max:150'],
            'experience.*.date_from' => ['nullable', 'date'],
            'experience.*.date_to' => ['nullable', 'date'],
            'experience.*.reason_for_leaving' => ['nullable', 'string', 'max:255'],

            'publication.*.title' => ['nullable', 'string', 'max:255'],
            'publication.*.publication_type' => ['nullable', 'string', 'max:50'],
            'publication.*.authors' => ['nullable', 'string'],
            'publication.*.journal_or_conference' => ['nullable', 'string', 'max:255'],

            'resume' => DocumentStore::rules(),
            'academic_transcript' => DocumentStore::rules(),
            'visa_or_ic' => DocumentStore::rules(),
        ]);

        $result = DB::transaction(function () use ($request, $data, $documents, $draft) {
            $profile = GraGaStudentProfile::updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'level_of_study' => $data['level_of_study'],
                    'programme' => $data['programme'],
                    'admission_date' => $data['admission_date'],
                ]
            );

            $detailAttributes = [
                'department_unit' => $data['department_unit'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'proposed_hours_per_week' => $data['proposed_hours_per_week'] ?? null,
                'service_start_date' => $data['service_start_date'],
                'service_end_date' => $data['service_end_date'],
                'proposed_allowance' => $data['proposed_allowance'],
            ];

            if ($draft) {
                $application = $draft;
                GaApplicationDetail::where('application_id', $application->id)->update($detailAttributes);
            } else {
                $application = Application::create([
                    'student_id' => $request->user()->id,
                    'module_type' => $this->moduleKey(),
                    'status' => Application::STATUS_DRAFT,
                ]);

                GaApplicationDetail::create(['application_id' => $application->id, 'profile_id' => $profile->id] + $detailAttributes);
            }

            $this->storeRepeatables($request, $profile);
            $this->attachDocuments($request, $application, $documents);

            return $application;
        });

        $application = $result;

        if ($action === 'save_draft') {
            return redirect()->route('ga-application.create')
                ->with('status', 'Draft saved — your progress is kept, come back anytime to finish it.');
        }

        $missing = $this->missingRequiredDocuments($application);

        $engine->submit($application);
        $application->student?->notify(new ApplicationSubmitted($application));

        $status = $missing->isEmpty()
            ? "GA application #{$application->id} submitted. Admin has been notified for document verification."
            : "GA application #{$application->id} submitted, but still missing: {$missing->implode(', ')}. Admin can't verify until you upload the rest; you'll get a weekly reminder until then.";

        return redirect()->route('applications.index')->with('status', $status);
    }

    /** @return \Illuminate\Support\Collection<int, string> labels of document types with no file attached yet */
    protected function missingRequiredDocuments(Application $application): \Illuminate\Support\Collection
    {
        $attached = ApplicationDocument::where('application_id', $application->id)->pluck('doc_type');

        return collect($this->documentTypes())
            ->reject(fn ($label) => $attached->contains($label))
            ->values();
    }

    /** Replace-the-set, same as GraApplicationController::storeRepeatables() — a resubmission replaces, not appends. */
    protected function storeRepeatables(Request $request, GraGaStudentProfile $profile): void
    {
        AcademicQualification::where('profile_id', $profile->id)->delete();
        foreach ($request->input('academic', []) as $row) {
            if (! empty($row['institution'])) {
                AcademicQualification::create(['profile_id' => $profile->id] + $row);
            }
        }

        WorkExperience::where('profile_id', $profile->id)->delete();
        foreach ($request->input('experience', []) as $row) {
            if (! empty($row['company_name'])) {
                WorkExperience::create(['profile_id' => $profile->id] + $row);
            }
        }

        Publication::where('profile_id', $profile->id)->delete();
        foreach ($request->input('publication', []) as $row) {
            if (! empty($row['title'])) {
                Publication::create(['profile_id' => $profile->id] + $row);
            }
        }
    }

    protected function attachDocuments(Request $request, Application $application, DocumentStore $documents): void
    {
        foreach ($this->documentTypes() as $field => $label) {
            if ($request->hasFile($field)) {
                $this->replaceDocument($application, $request->file($field), $label, $documents);
            }
        }
    }

    /** The student's own read-only view of their GA application, same pattern as GraApplicationController::showMine(). */
    public function showMine(Request $request, Application $application, WorkflowEngine $engine)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        return view('haziq::ga_application.show', $this->detailViewData($application) + [
            'stage' => $engine->currentStage($application),
            'canDecide' => false,
            'canCorrect' => false,
            'canEdit' => $this->canStudentEdit($application),
            'viewerIsStudent' => true,
            'missingDocuments' => DocumentCompleteness::missingFor($application),
        ]);
    }

    /** @return array<string, mixed> everything show() and showMine() both need, built once. */
    protected function detailViewData(Application $application): array
    {
        $detail = GaApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::find($detail->profile_id);

        return [
            'application' => $application,
            'detail' => $detail,
            'profile' => $profile,
            'academics' => $profile ? AcademicQualification::where('profile_id', $profile->id)->get() : collect(),
            'experiences' => $profile ? WorkExperience::where('profile_id', $profile->id)->get() : collect(),
            'publications' => $profile ? Publication::where('profile_id', $profile->id)->get() : collect(),
            'openCorrections' => ApplicationCorrectionRequest::where('application_id', $application->id)->whereNull('resolved_at')->get(),
            'resolvedCorrections' => ApplicationCorrectionRequest::where('application_id', $application->id)->whereNotNull('resolved_at')->latest('resolved_at')->get(),
            'documentTypes' => $this->documentTypes(),
            'sectionLabels' => $this->sectionLabels(),
            'sectionFieldLabels' => $this->sectionFieldLabels(),
        ];
    }

    /** Full detail for an approver — every section, every document, approval history, decision form when it's this user's turn. */
    public function show(Request $request, Application $application, WorkflowEngine $engine)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR || $user->role === Roles::RESEARCH_CENTRE,
            403
        );

        $stage = $engine->currentStage($application);

        return view('haziq::ga_application.show', $this->detailViewData($application) + [
            'stage' => $stage,
            'canDecide' => $stage && $stage->role === $user->role,
            // Viewing show() is open to Research Centre too (role check
            // above), but correcting is not -- see
            // canRequestCorrectionRole()'s own docblock.
            'canCorrect' => $this->canRequestCorrectionRole($user) && $this->canStillFlag($user, $application),
            'canEdit' => false,
            'viewerIsStudent' => false,
        ]);
    }

    /** Every applicant, visible to all four approver roles, with the bottleneck bar — mirrors GraApplicationController::index(). */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR || $user->role === Roles::RESEARCH_CENTRE,
            403
        );

        $selectedStage = $request->query('stage');

        $applications = Application::where('module_type', $this->moduleKey())
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->when($selectedStage, fn ($q) => $q->where('status', Application::STATUS_PENDING)->where('current_stage', $selectedStage))
            ->with('student')
            ->latest('submitted_at')
            ->paginate(25)
            ->withQueryString();

        $details = GaApplicationDetail::whereIn('application_id', $applications->pluck('id'))
            ->with('profile')
            ->get()
            ->keyBy('application_id');

        return view('haziq::ga_application.index', [
            'applications' => $applications,
            'details' => $details,
            'selectedStage' => $selectedStage,
            'selectedStageLabel' => $selectedStage
                ? collect((new \App\Modules\Haziq\Workflows\GaApplicationWorkflow())->stages())
                    ->firstWhere('key', $selectedStage)?->label
                : null,
            'bottleneck' => $this->pendingByStage(),
            // GA Extension's own queue (a tab here, not a separate
            // sidebar entry -- confirmed explicitly 2026-11-10: "buaag ga
            // extentions dekat actions, buat je kat page ga boleh
            // filter") is reachable only by the roles that actually hold
            // a stage on that chain. Research Centre has none there, so
            // showing the tab for them would just be a dead 403 link.
            'showExtensionTab' => $user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR || $user->role === Role::SENIOR_DIRECTOR_CGS,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, array{key: string, label: string, count: int}> */
    protected function pendingByStage(): \Illuminate\Support\Collection
    {
        $counts = Application::where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_PENDING)
            ->selectRaw('current_stage, count(*) as total')
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage');

        return collect((new \App\Modules\Haziq\Workflows\GaApplicationWorkflow())->stages())
            ->map(fn ($stage) => [
                'key' => $stage->key,
                'label' => $stage->label,
                'count' => (int) ($counts[$stage->key] ?? 0),
            ]);
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        // The engine's queue is role-scoped, not person-scoped -- any
        // Supervisor would otherwise see every student's application
        // waiting at this stage, not only their own supervisees. Confirmed
        // explicitly 2026-11-10: "bukan semua supervisor approve for any
        // student." Same pattern as Nureen's SupervisionController.
        // Same reasoning for Research Centre as supervisor just above --
        // confirmed explicitly 2026-11-10: "research centre tu... ikut
        // own student respective research centre au sebab student kan
        // lain lain department of studies." There is no dedicated
        // research-centre-assignment column (unlike supervisor_id);
        // Research Centre reviewers are matched to students by
        // `department`, the same field Chair/Academic Executive are
        // already scoped by elsewhere (Role::isDepartmentScoped()) --
        // Research Centre just was never added to that generic Core
        // list, and adding it there would affect every other module
        // that list touches, not just this one, so it is scoped here
        // instead, the same way supervisor already is.
        $queue = $this->queueFor($request, $engine, ['documents'],
            function ($query, Stage $stage) use ($request) {
                if ($stage->key === 'supervisor') {
                    $query->whereHas('student', fn ($s) => $s->where('supervisor_id', $request->user()->id));
                } elseif ($stage->key === 'research_centre_interview') {
                    $query->whereHas('student', fn ($s) => $s->where('department', $request->user()->department));
                }
            });

        $details = GaApplicationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()->keyBy('application_id');

        $openCorrections = ApplicationCorrectionRequest::whereIn('application_id', $queue['applications']->pluck('id'))
            ->whereNull('resolved_at')
            ->get()
            ->groupBy('application_id');

        // Only meaningful at admin_verify -- see decide()'s own identical
        // gate. GA has no extension of its own through this controller
        // (see KnowledgeBase's "DO NOT CONFUSE" note), so every row here is
        // a 'new' application, unlike GRA's.
        $missingDocuments = $queue['applications']->mapWithKeys(fn ($application) => [
            $application->id => DocumentCompleteness::missingFor($application),
        ]);

        return view('haziq::ga_application.queue', $queue + [
            'details' => $details,
            'openCorrections' => $openCorrections,
            'documentTypes' => $this->documentTypes(),
            'sectionLabels' => $this->sectionLabels(),
            'sectionFieldLabels' => $this->sectionFieldLabels(),
            'missingDocuments' => $missingDocuments,
            // The queue is shared with Research Centre, who can view it
            // but never correct -- see canRequestCorrectionRole()'s own
            // docblock. Confirmed explicitly 2026-11-10.
            'canCorrect' => $this->canRequestCorrectionRole($request->user()),
        ]);
    }

    /**
     * Research Centre's first move once the application reaches it —
     * sets the interview date and notifies the student, but does *not*
     * advance the workflow: the application stays pending at this same
     * stage until the actual Pass/Fail decide() call below, same as a
     * real interview has to happen before its outcome can be recorded.
     * Confirmed explicitly 2026-11-03: "sent in interview reminder pada
     * student with date."
     */
    public function scheduleInterview(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($request->user()->role === Roles::RESEARCH_CENTRE, 403);
        abort_unless($application->student?->department === $request->user()->department, 403, 'This student is not in your department.');

        // One date, two times on it -- not two independent timestamps that
        // could land on different days. Confirmed explicitly 2026-11-10:
        // "date taleh sampai 2 hari set satu date je, timing je set from
        // what time end what time." Measurement only (see
        // researchCentreDelayHours()), not a hard gate on decide() --
        // confirmed the same day. Hour/minute/AM-PM instead of a raw 24h
        // <input type="time"> -- confirmed explicitly 2026-11-10: "set
        // timing tu cam tak user friendly je, buat lah am pm features
        // terima 1-12 tu." Minute is any value 0-59, not stepped -- "yang
        // minit tu tak kisah lah nak tulis berapa pun," confirmed the
        // same day.
        $data = $request->validate([
            'interview_date' => ['required', 'date_format:Y-m-d'],
            'interview_start_hour' => ['required', 'integer', 'between:1,12'],
            // 'numeric', not 'integer' -- PHP's filter_var(FILTER_VALIDATE_INT)
            // rejects a leading zero ("00".."09") as looking like octal,
            // which a typed-in minute legitimately is. is_numeric() has no
            // such quirk.
            'interview_start_minute' => ['required', 'numeric', 'between:0,59'],
            'interview_start_meridiem' => ['required', 'in:AM,PM'],
            'interview_end_hour' => ['required', 'integer', 'between:1,12'],
            'interview_end_minute' => ['required', 'numeric', 'between:0,59'],
            'interview_end_meridiem' => ['required', 'in:AM,PM'],
        ]);

        $interviewDate = Carbon::parse(sprintf(
            '%s %d:%02d %s',
            $data['interview_date'], $data['interview_start_hour'], $data['interview_start_minute'], $data['interview_start_meridiem']
        ));
        $interviewEndsAt = Carbon::parse(sprintf(
            '%s %d:%02d %s',
            $data['interview_date'], $data['interview_end_hour'], $data['interview_end_minute'], $data['interview_end_meridiem']
        ));

        if ($interviewEndsAt->lessThanOrEqualTo($interviewDate)) {
            return back()->with('error', 'The interview must end after it starts.')->withInput();
        }

        $detail = GaApplicationDetail::where('application_id', $application->id)->firstOrFail();
        // interview_scheduled_at is set once, here, the moment Research
        // Centre actually does this task -- never touched again, even if
        // the date is later rescheduled, since that is a different
        // question (DelayByActorReport::rows() reads it as "when the
        // scheduling task itself was done").
        $detail->update([
            'interview_date' => $interviewDate,
            'interview_ends_at' => $interviewEndsAt,
            'interview_scheduled_at' => $detail->interview_scheduled_at ?? now(),
        ]);

        $application->student?->notify(new GaInterviewScheduled($application, $detail));

        return back()->with('status', 'Interview scheduled — the student has been notified.');
    }

    /**
     * Overrides the trait for the stages that need more than a plain
     * approve/reject:
     *
     *  - Admin's stage is verification only, same as GRA's admin_verify —
     *    never rejects outright; a problem goes through Request
     *    Correction instead.
     *  - Research Centre's stage records Pass/Fail with a report
     *    attached, and can't be decided before scheduleInterview() has
     *    actually set a date — confirmed explicitly 2026-11-03: a Fail
     *    rejects outright here, CGS never sees it; a Pass proceeds to
     *    CGS.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        // The queue above already hides another supervisor's (or another
        // department's Research Centre's) students, but that filters a
        // list, not this direct POST -- a crafted request naming someone
        // else's application id still needs its own guard.
        if ($stage?->key === 'supervisor' && $application->student?->supervisor_id !== $request->user()->id) {
            abort(403, 'This student is not under your supervision.');
        }

        if ($stage?->key === 'research_centre_interview' && $application->student?->department !== $request->user()->department) {
            abort(403, 'This student is not in your department.');
        }

        if ($stage?->key === 'admin_verify') {
            $data = $request->validate(['decision' => ['required', 'in:approve']]);
            $data['remarks'] = null;

            // Confirmed explicitly 2026-11-10: Admin specifically can't say
            // "verified" while a required document is still missing — see
            // the identical GRA guard in GraApplicationController::decide()
            // for the full reasoning. DocumentCompleteness's own "never
            // gates routing" rule still holds for every other stage.
            if (! DocumentCompleteness::isComplete($application)) {
                return back()->with('error', 'All required documents must be uploaded before this application can be verified and advanced.');
            }
        } elseif ($stage?->key === 'research_centre_interview') {
            abort_unless($detail?->interview_date, 404, 'Schedule the interview first.');

            // A reason is mandatory to fail, optional to pass.
            $interview = $request->validate([
                'decision' => ['required', 'in:approve,reject'],
                'interview_notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
                'interview_report' => DocumentStore::rules(),
            ], [
                'interview_notes.required_if' => 'Notes are required when failing an interview.',
            ]);

            $detail->update([
                'interview_result' => $interview['decision'] === 'approve' ? 'pass' : 'fail',
                'interview_notes' => $interview['interview_notes'] ?? null,
                'interviewed_by' => $request->user()->id,
            ]);

            if ($request->hasFile('interview_report')) {
                $this->replaceDocument($application, $request->file('interview_report'), 'Interview Report', $documents);
            }

            $data = ['decision' => $interview['decision'], 'remarks' => $interview['interview_notes'] ?? null];
        } else {
            $data = $request->validate([
                'decision' => ['required', 'in:approve,reject'],
                'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
            ], [
                'remarks.required_if' => 'A reason is required to reject an application.',
            ]);
        }

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED) {
            $this->onApproved($decided, $documents);
        } elseif ($decided->status === Application::STATUS_REJECTED) {
            $application->student?->notify(new ApplicationRejected($application, $stage, $data['remarks']));

            // Research Centre failing an interview is the one rejection
            // in this chain nobody else used to hear about at all — the
            // application was already visible to every approver
            // (index()/show() never filtered it out) and already
            // unactionable (decide()'s own "already decided" guard), so
            // the only real gap was that they never found out it
            // happened. Confirmed explicitly 2026-11-06: "sepatutnya
            // even yang fail interview semua boleh tengok dapat noti,
            // tapi no actions."
            if ($stage?->key === 'research_centre_interview') {
                $this->stakeholders($decided)->each(
                    fn (User $u) => $u->notify(new GaInterviewFailed($decided, $data['remarks']))
                );
            }
        } elseif ($decided->status === Application::STATUS_PENDING) {
            $this->notifyNextApprover($decided, $engine);

            // A Fail already tells the student outright (ApplicationRejected
            // above); a Pass used to leave them to find out only by
            // checking the page -- notifyNextApprover() above only reaches
            // CGS. Confirmed explicitly 2026-11-10. See GaInterviewPassed's
            // own docblock.
            if ($stage?->key === 'research_centre_interview') {
                $application->student?->notify(new GaInterviewPassed($decided));
            }
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    protected function notifyNextApprover(Application $application, WorkflowEngine $engine): void
    {
        $stage = $engine->currentStage($application);

        if (! $stage) {
            return;
        }

        foreach (User::where('role', $stage->role)->get() as $approver) {
            $approver->notify(new StageAwaitingReview($application, $stage));
        }
    }

    /**
     * Admin + the full CGS team + the application's own student's
     * assigned Supervisor — same shape as CandidatureAppealController's
     * own stakeholders(), reused here for GaInterviewFailed.
     *
     * @return Collection<int, User>
     */
    protected function stakeholders(Application $application): Collection
    {
        $application->loadMissing('student.supervisor');

        $recipients = User::where('role', Role::ADMIN)->get()
            ->merge(User::whereIn('role', Role::cgsTeam())->get());

        if ($supervisor = $application->student?->supervisor) {
            $recipients->push($supervisor);
        }

        return $recipients->unique('id');
    }

    /**
     * No Stage Gate schedule, no allowance tracking — confirmed
     * explicitly 2026-11-03: "after approve takdelah stage gate or
     * allowance bagai bagai tu, die just application approve." A GA
     * appointment is just the approved record plus its offer letters.
     */
    protected function onApproved(Application $application, DocumentStore $documents): void
    {
        $detail = GaApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);

        foreach (['offer_letter_pdf' => 'GA Offer Letter', 'program_offer_letter_pdf' => 'GA Program Offer Letter'] as $view => $label) {
            $pdf = Pdf::loadView("haziq::ga_application.{$view}", [
                'application' => $application, 'detail' => $detail, 'profile' => $profile, 'student' => $application->student,
            ]);
            $doc = $documents->storeGenerated($application, $pdf->output(), strtolower(str_replace(' ', '-', $label))."-{$application->id}.pdf", $label);
            $application->student?->notify(new LetterIssued($application, $doc, $label));
        }
    }

    /**
     * Pending, or approved and still an active appointment — same as
     * GraApplicationController::canStudentEdit(), mirrored here since GA
     * has its own detail table.
     */
    protected function canStudentEdit(Application $application): bool
    {
        if ($application->status === Application::STATUS_PENDING) {
            return true;
        }

        if ($application->status !== Application::STATUS_APPROVED) {
            return false;
        }

        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        return $detail && ! $detail->isTerminated();
    }

    /** Open any time the application is editable (pending, or approved and not terminated), flagged or not — see GraApplicationController::showCorrection() for why. */
    public function showCorrection(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($this->canStudentEdit($application), 404, 'This application can no longer be edited.');

        $open = ApplicationCorrectionRequest::where('application_id', $application->id)->whereNull('resolved_at')->get();
        $detail = GaApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);

        return view('haziq::ga_application.correction', [
            'application' => $application,
            'detail' => $detail,
            'profile' => $profile,
            'open' => $open,
            'flaggedFields' => $open->whereNotNull('target_field')->keyBy(fn ($r) => $r->target_type.'.'.$r->target_field),
            'flaggedSections' => $open->whereNull('target_field')->whereIn('target_type', array_keys($this->sectionLabels()))->pluck('target_type'),
            'flaggedDocuments' => $open->where('target_type', 'document')->pluck('target_label'),
            'academics' => AcademicQualification::where('profile_id', $profile->id)->get(),
            'experiences' => WorkExperience::where('profile_id', $profile->id)->get(),
            'publications' => Publication::where('profile_id', $profile->id)->get(),
            'documentTypes' => $this->documentTypes(),
            'existingDocuments' => ApplicationDocument::where('application_id', $application->id)->get()->keyBy('doc_type'),
        ]);
    }

    /**
     * Mirrors GraApplicationController::storeCorrection() — GA Details +
     * profile fields + Section D/E/F are always editable, every time, not
     * just documents. No Stage Gate recompute here any more — GA never
     * generates a schedule in the first place (see onApproved()).
     */
    public function storeCorrection(Request $request, Application $application, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($this->canStudentEdit($application), 404, 'This application can no longer be edited.');

        $open = ApplicationCorrectionRequest::where('application_id', $application->id)->whereNull('resolved_at')->get();

        $detail = GaApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);

        $data = $request->validate([
            'department_unit' => ['nullable', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'proposed_hours_per_week' => ['nullable', 'integer', 'min:1', 'max:21'],
            'service_start_date' => ['required', 'date'],
            'service_end_date' => ['required', 'date', 'after:service_start_date'],
            'proposed_allowance' => ['required', 'numeric', 'min:0'],

            'level_of_study' => ['required', 'in:master,phd'],
            'programme' => ['required', 'string', 'max:150'],
            'admission_date' => ['required', 'date'],
        ]);

        $detail->update([
            'department_unit' => $data['department_unit'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'proposed_hours_per_week' => $data['proposed_hours_per_week'] ?? null,
            'service_start_date' => $data['service_start_date'],
            'service_end_date' => $data['service_end_date'],
            'proposed_allowance' => $data['proposed_allowance'],
        ]);

        $profile->update([
            'level_of_study' => $data['level_of_study'],
            'programme' => $data['programme'],
            'admission_date' => $data['admission_date'],
        ]);

        $request->validate(['academic.*.institution' => ['nullable', 'string', 'max:150']]);
        AcademicQualification::where('profile_id', $profile->id)->delete();
        foreach ($request->input('academic', []) as $row) {
            if (! empty($row['institution'])) {
                AcademicQualification::create(['profile_id' => $profile->id] + $row);
            }
        }

        $request->validate(['experience.*.company_name' => ['nullable', 'string', 'max:150']]);
        WorkExperience::where('profile_id', $profile->id)->delete();
        foreach ($request->input('experience', []) as $row) {
            if (! empty($row['company_name'])) {
                WorkExperience::create(['profile_id' => $profile->id] + $row);
            }
        }

        $request->validate(['publication.*.title' => ['nullable', 'string', 'max:255']]);
        Publication::where('profile_id', $profile->id)->delete();
        foreach ($request->input('publication', []) as $row) {
            if (! empty($row['title'])) {
                Publication::create(['profile_id' => $profile->id] + $row);
            }
        }

        foreach ($this->documentTypes() as $field => $label) {
            if ($request->hasFile($field)) {
                $this->replaceDocument($application, $request->file($field), $label, $documents);
            }
        }

        ApplicationCorrectionRequest::where('application_id', $application->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        User::whereIn('id', $open->pluck('requested_by')->unique())->get()->each(
            fn (User $requester) => $requester->notify(new ApplicationCorrectionResubmitted($application))
        );

        return redirect()->route('applications.index')
            ->with('status', $open->isNotEmpty()
                ? 'Corrections submitted. The approver(s) who flagged them have been notified.'
                : 'Application updated.');
    }
}
