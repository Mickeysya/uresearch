<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\Concerns\HandlesApplicationCorrections;
use App\Modules\Haziq\Models\AcademicQualification;
use App\Modules\Haziq\Models\AllowanceRecommendationNote;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\GraTermsAcceptance;
use App\Modules\Haziq\Models\Publication;
use App\Modules\Haziq\Models\WorkExperience;
use App\Modules\Haziq\Notifications\AllowanceRecommendationFlagged;
use App\Modules\Haziq\Notifications\AllowanceRecommendationUpdated;
use App\Modules\Haziq\Notifications\ApplicationCorrectionResubmitted;
use App\Modules\Haziq\Notifications\ApplicationRejected;
use App\Modules\Haziq\Notifications\ApplicationSubmitted;
use App\Modules\Haziq\Notifications\LetterIssued;
use App\Modules\Haziq\Notifications\StageAwaitingReview;
use App\Modules\Haziq\Support\StageGateEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class GraApplicationController extends Controller
{
    use ApprovesApplications, HandlesApplicationCorrections;

    protected function moduleKey(): string
    {
        return 'gra_application';
    }

    /** Shared by the upload form, attachDocuments(), and the Request Correction dropdown. */
    protected function documentTypes(): array
    {
        return [
            'resume' => 'Resume/CV',
            'academic_transcript' => 'Academic Transcript',
            'visa_or_ic' => 'Visa / IC',
            'admission_letter' => 'UTP Admission Offer Letter',
            'bank_letter' => 'Bank Account Details Letter',
            'study_plan' => 'Study Plan (Gantt Chart)',
            'grant_utilization_approval' => 'Grant Utilization Approval',
            'supervisor_supporting_letter' => 'GRA Supporting Letter from Supervisor',
        ];
    }

    /** The five sections any approver can return to the student for correction. */
    protected function sectionLabels(): array
    {
        return [
            'section_b' => 'Section B — Project Details',
            'section_c' => 'Section C — GRA Details',
            'section_d' => 'Section D — Academic Qualification',
            'section_e' => 'Section E — Working Experience',
            'section_f' => 'Section F — Publications',
        ];
    }

    /**
     * Field-level flagging for Sections B and C — both are a single row of
     * named inputs, so "Project Title is wrong" is meaningful. D/E/F are
     * repeatable tables with no fixed field list (a student might have one
     * academic record or five), so they stay whole-section flags — no entry
     * here for them, and HandlesApplicationCorrections falls back
     * accordingly.
     */
    protected function sectionFieldLabels(): array
    {
        return [
            'section_b' => [
                'project_leader_name' => 'Project Leader',
                'project_leader_contact' => 'Project Leader Contact No.',
                'project_title' => 'Project Title',
                'funding_source' => 'Funding Source',
                'grant_name' => 'Name of Grant',
                'cost_centre' => 'Cost Centre',
                'centre_name' => 'Name of Centre',
                'service_start_date' => 'Service Start Date',
            ],
            'section_c' => [
                'level_of_study' => 'Level of Study',
                'programme' => 'Programme',
                'mode_of_study' => 'Mode of Study',
                'admission_date' => 'Admission Date',
                'thesis_title' => 'Thesis Title',
                'co_supervisor_id' => 'Co-Supervisor',
                'ic_passport_no' => 'I/C or Passport No.',
                'bank_name' => 'Name of Bank',
                'bank_account_no' => 'Account No.',
            ],
        ];
    }

    /**
     * The GRA Scheme Terms and Conditions — accepted once per student,
     * never per application (see GraTermsAcceptance's migration). Gates
     * both create() and createExtension(), checked again in store() and
     * storeExtension() in case either is posted to directly.
     */
    protected function hasAcceptedTerms(User $user): bool
    {
        return GraTermsAcceptance::where('user_id', $user->id)->exists();
    }

    public function showTerms(Request $request)
    {
        return view('haziq::gra_application.terms', [
            'intent' => $request->query('intent') === 'extension' ? 'extension' : 'new',
        ]);
    }

    public function acceptTerms(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agree' => ['required', 'accepted'],
            'intent' => ['required', 'in:new,extension'],
        ]);

        GraTermsAcceptance::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['accepted_at' => now()]
        );

        return redirect()->route(
            $data['intent'] === 'extension' ? 'gra-application.extension.create' : 'gra-application.create'
        );
    }

    /**
     * The list itself always shows every applicant — name, programme,
     * submitted date, current stage — to all four approver roles; the
     * bottleneck bar just narrows it to one stage via `?stage=<key>`, it
     * doesn't gate whether the list is visible at all. What IS gated is the
     * link through to full *detail*: a name here is only clickable once the
     * application has actually reached the viewer's own stage — see
     * show()'s access check. Overview visibility and detail visibility are
     * deliberately different things.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        $selectedStage = $request->query('stage');

        $applications = Application::where('module_type', $this->moduleKey())
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->when($selectedStage, fn ($q) => $q->where('status', Application::STATUS_PENDING)->where('current_stage', $selectedStage))
            ->with('student')
            ->latest('submitted_at')
            ->paginate(25)
            ->withQueryString();

        $profiles = GraApplicationDetail::whereIn('application_id', $applications->pluck('id'))
            ->with('profile')
            ->get()
            ->keyBy('application_id');

        $viewerStageIndex = $this->stageIndexFor($user);

        return view('haziq::gra_application.index', [
            'applications' => $applications,
            'profiles' => $profiles,
            'selectedStage' => $selectedStage,
            'selectedStageLabel' => $selectedStage
                ? collect((new \App\Modules\Haziq\Workflows\GraApplicationWorkflow())->stages())
                    ->firstWhere('key', $selectedStage)?->label
                : null,
            'bottleneck' => $this->pendingByStage(),
            'canViewDetail' => fn (Application $application) => $this->canViewDetail($application, $viewerStageIndex, $user),
        ]);
    }

    /**
     * How many stages into the chain this role's own stage sits, 0-based —
     * Admin is 0, CGS Staff 1, Supervisor 2, Senior Director 3. Used to
     * decide "has this application reached me yet". Senior Executive CGS
     * and Manager CGS own no stage in this particular chain, so they fall
     * back to CGS Staff's threshold — they're CGS staff in spirit even
     * though only Non-Executive CGS is actually named in this chain.
     */
    protected function stageIndexFor(User $user): ?int
    {
        $stages = (new \App\Modules\Haziq\Workflows\GraApplicationWorkflow())->stages();

        foreach ($stages as $i => $stage) {
            if ($stage->role === $user->role) {
                return $i;
            }
        }

        if ($user->isCgs()) {
            foreach ($stages as $i => $stage) {
                if ($stage->role === Role::NON_EXEC_CGS) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Same lookup as stageIndexFor(), but the stage key rather than its position — see canStillFlag(). */
    protected function viewerStageKeyFor(User $user): ?string
    {
        $stages = (new \App\Modules\Haziq\Workflows\GraApplicationWorkflow())->stages();

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
     * Flagging closes once the application is no longer pending (approved
     * or rejected — nothing left to gate a correction against), and
     * separately, per role, once that role's own stage has already been
     * decided on this application: once CGS has approved and moved it on
     * to Supervisor, CGS is done with it and shouldn't be able to pull it
     * back. A role whose stage hasn't been reached (or has no stage of its
     * own) can still flag — this only closes the window after, not before.
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

    protected function currentStageIndex(Application $application): ?int
    {
        $stages = (new \App\Modules\Haziq\Workflows\GraApplicationWorkflow())->stages();

        foreach ($stages as $i => $stage) {
            if ($stage->key === $application->current_stage) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Once an application is no longer pending (approved or rejected) it's
     * over, so every approver can see it regardless of index — this gate
     * only withholds detail from applications still in flight that haven't
     * reached the viewer's own stage yet.
     *
     * Except — an approver who can still flag something on this
     * application (canStillFlag(), for a correction, or for the
     * allowance via Supervisor/Senior Director specifically) has to be
     * able to open the page to do it. HandlesApplicationCorrections'
     * whole premise is "any approver can flag an issue on any
     * application, whether or not it has reached their stage yet" — the
     * stage gate above was blocking that from ever being reachable before
     * this, which is exactly the Supervisor/Senior Director allowance-flag
     * gap this fixes.
     */
    protected function canViewDetail(Application $application, ?int $viewerStageIndex, User $user): bool
    {
        if ($application->status !== Application::STATUS_PENDING) {
            return true;
        }

        if ($viewerStageIndex === null) {
            return true;
        }

        $currentIndex = $this->currentStageIndex($application);

        if ($currentIndex === null || $viewerStageIndex <= $currentIndex) {
            return true;
        }

        return $this->canStillFlag($user, $application);
    }

    /**
     * How many applications are sitting pending at each stage, in chain
     * order — "where is the bottleneck", not just a total count. One query,
     * grouped, then mapped onto every declared stage so a stage with zero
     * pending still shows as a zero bar rather than being silently absent.
     * Carries each stage's key so the chart's click handler can turn "bar 2"
     * into `?stage=cgs_review`.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, label: string, count: int}>
     */
    protected function pendingByStage(): \Illuminate\Support\Collection
    {
        $counts = Application::where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_PENDING)
            ->selectRaw('current_stage, count(*) as total')
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage');

        return collect((new \App\Modules\Haziq\Workflows\GraApplicationWorkflow())->stages())
            ->map(fn ($stage) => [
                'key' => $stage->key,
                'label' => $stage->label,
                'count' => (int) ($counts[$stage->key] ?? 0),
            ]);
    }

    /** Full detail — every section, every document, approval history. Decision form appears only when it's this user's turn. */
    public function show(Request $request, Application $application, WorkflowEngine $engine)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        // Enforced here, not just hidden in the list's UI — a name not
        // being a link on the index page is cosmetic; this is what actually
        // stops someone opening the URL directly before their stage.
        abort_unless(
            $this->canViewDetail($application, $this->stageIndexFor($user), $user),
            403,
            'This application has not reached your stage yet.'
        );

        $stage = $engine->currentStage($application);

        return view('haziq::gra_application.show', $this->detailViewData($application) + [
            'stage' => $stage,
            'canDecide' => $stage && $stage->role === $user->role,
            // Any approver can flag a correction on any application not
            // yet reached its end AND not already decided by their own
            // stage — see canStillFlag().
            'canCorrect' => $this->canStillFlag($user, $application),
            'canEdit' => false,
            'viewerIsStudent' => false,
            // Supervisor/Senior Director only — see flagAllowance().
            'canFlagAllowance' => in_array($user->role, [Role::SUPERVISOR, Role::SENIOR_DIRECTOR_CGS], true),
            'allowanceNotes' => AllowanceRecommendationNote::where('application_id', $application->id)
                ->with('author')->latest()->get(),
            // Same role that sets it originally — not gated to their own
            // current stage, since by the time there's something worth
            // revising the application has usually already moved past
            // cgs_review. See editAllowance().
            'canEditAllowance' => $user->role === Role::NON_EXEC_CGS && $application->status === Application::STATUS_PENDING,
        ]);
    }

    /**
     * The student's own read-only view of what they submitted — same
     * template as the approver's full detail, but with no decision form and
     * no Request Correction control. The Edit button is available any time
     * the application is still pending, flagged or not — a student doesn't
     * need to wait for an approver to notice something before fixing it
     * themselves. If something *is* flagged, the edit form highlights
     * exactly what; see showCorrection().
     */
    public function showMine(Request $request, Application $application, WorkflowEngine $engine)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        $data = $this->detailViewData($application);

        return view('haziq::gra_application.show', $data + [
            'stage' => $engine->currentStage($application),
            'canDecide' => false,
            'canCorrect' => false,
            'canEdit' => $this->canStudentEdit($application),
            'viewerIsStudent' => true,
            'canFlagAllowance' => false,
            // Never queried, let alone shown, for the student's own view —
            // see AllowanceRecommendationNote's migration for why.
            'allowanceNotes' => collect(),
            'canEditAllowance' => false,
        ]);
    }

    /**
     * Pending, or approved and still an active appointment — a student can
     * keep updating every section of their own application at any time,
     * not just while it's awaiting a decision. Confirmed 2026-10-14: this
     * was previously locked to pending only. A rejected application stays
     * locked (the student resubmits a fresh one instead, per the existing
     * reapply flow); a terminated one does too, same reasoning.
     */
    protected function canStudentEdit(Application $application): bool
    {
        if ($application->status === Application::STATUS_PENDING) {
            return true;
        }

        if ($application->status !== Application::STATUS_APPROVED) {
            return false;
        }

        $detail = GraApplicationDetail::where('application_id', $application->id)->first();

        return $detail && ! $detail->isTerminated();
    }

    /** @return array<string, mixed> everything show() and showMine() both need, built once. */
    protected function detailViewData(Application $application): array
    {
        $detail = GraApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::find($detail->profile_id);

        return [
            'application' => $application,
            'detail' => $detail,
            'terminated' => $detail->isTerminated(),
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

    /**
     * New GRA application. Section C/D/E/F pre-fill only when continuing
     * an actual saved draft of this student's own — not merely because a
     * profile happens to exist. A genuinely new application (no draft in
     * progress) always starts blank, even for a returning applicant,
     * confirmed explicitly 2026-10-14: every application gets retyped
     * fresh, nothing carries over silently just because the student
     * applied before.
     */
    public function create(Request $request)
    {
        // Must accept the GRA Scheme Terms and Conditions before the form
        // is even reachable — "patutnya before student isi form diorang
        // kene accept term and regulation baru leh isi form tu", 2026-11.
        if (! $this->hasAcceptedTerms($request->user())) {
            return redirect()->route('gra-application.terms', ['intent' => 'new']);
        }

        // Without this, a student could always reach a blank form and
        // submit a second 'new' application while the first is still
        // in flight — there was nothing stopping it before.
        if ($existing = $this->activeNewApplication($request)) {
            return redirect()->route('gra-application.mine', $existing)
                ->with('status', 'You already have a GRA application in progress — here it is.');
        }

        // A draft isn't "in progress" the way activeNewApplication() means
        // it (that redirects away to a read-only view) — it's unfinished,
        // so this screen loads it pre-filled for the student to keep
        // working on, same screen either way.
        $draft = $this->draftApplication($request);
        $draftDetail = $draft ? GraApplicationDetail::where('application_id', $draft->id)->first() : null;
        $profile = $draft ? GraGaStudentProfile::find($draftDetail->profile_id) : null;

        return view('haziq::gra_application.form', [
            'profile' => $profile,
            'academics' => $profile ? AcademicQualification::where('profile_id', $profile->id)->get() : collect(),
            'experiences' => $profile ? WorkExperience::where('profile_id', $profile->id)->get() : collect(),
            'publications' => $profile ? Publication::where('profile_id', $profile->id)->get() : collect(),
            'extension' => false,
            'parent' => null,
            'draft' => $draft,
            'draftDetail' => $draftDetail,
            'draftDocuments' => $draft ? ApplicationDocument::where('application_id', $draft->id)->get() : collect(),
            'supervisors' => User::where('role', Role::SUPERVISOR)->orderBy('name')->get(),
        ]);
    }

    /** The student's own unsubmitted 'new' GRA application, if any — see draftApplication() callers for how it's used. */
    protected function draftApplication(Request $request): ?Application
    {
        return GraApplicationDetail::where('application_type', 'new')
            ->whereHas('application', fn ($q) => $q
                ->where('student_id', $request->user()->id)
                ->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_DRAFT))
            ->with('application')
            ->latest('id')
            ->first()
            ?->application;
    }

    /**
     * Two actions behind one route: Save Draft (keeps status DRAFT, never
     * calls engine->submit()) and Submit Application (the original
     * behaviour). Project details, GRA details and service_start_date stay
     * required either way — they're needed to even construct a valid
     * detail/profile row, and were already always on the form. Documents
     * are the one thing genuinely optional for a draft save; they're only
     * actually enforced at Submit time, checking both this request's
     * uploads and whatever was already attached from an earlier draft
     * save — see missingRequiredDocuments().
     */
    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        // Same guards as create(), checked again here in case of a direct
        // POST — the GET-time redirects are not themselves authorization checks.
        abort_unless($this->hasAcceptedTerms($request->user()), 403, 'Accept the GRA Scheme Terms and Conditions first.');

        if ($existing = $this->activeNewApplication($request)) {
            return redirect()->route('gra-application.mine', $existing)
                ->with('status', 'You already have a GRA application in progress.');
        }

        $action = $request->input('action') === 'save_draft' ? 'save_draft' : 'submit';
        $draft = $this->draftApplication($request);

        $data = $this->validateApplication($request);

        $result = DB::transaction(function () use ($request, $data, $documents, $draft) {
            $profile = $this->storeProfile($request, $data);

            $detailAttributes = [
                'profile_id' => $profile->id,
                'project_leader_name' => $data['project_leader_name'],
                'project_leader_contact' => $data['project_leader_contact'] ?? null,
                'project_title' => $data['project_title'],
                'funding_source' => $data['funding_source'],
                'grant_name' => $data['grant_name'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'centre_name' => $data['centre_name'] ?? null,
                'expected_reporting_date' => $data['expected_reporting_date'] ?? null,
                'service_start_date' => $data['service_start_date'],
                'service_end_date' => GraApplicationDetail::computeServiceEndDate(Carbon::parse($data['service_start_date']), $profile->level_of_study),
                // proposed_allowance stays null — CGS sets it when they approve.
            ];

            if ($draft) {
                $application = $draft;
                GraApplicationDetail::where('application_id', $application->id)->update($detailAttributes);
            } else {
                $application = Application::create([
                    'student_id' => $request->user()->id,
                    'module_type' => $this->moduleKey(),
                    'status' => Application::STATUS_DRAFT,
                ]);

                GraApplicationDetail::create(['application_id' => $application->id, 'application_type' => 'new'] + $detailAttributes);
            }

            $this->storeRepeatables($request, $profile);
            $this->attachDocuments($request, $application, $documents);

            return $application;
        });

        $application = $result;

        if ($action === 'save_draft') {
            return redirect()->route('gra-application.create')
                ->with('status', 'Draft saved — your progress is kept, come back anytime to finish it.');
        }

        $missing = $this->missingRequiredDocuments($application);

        if ($missing->isNotEmpty()) {
            return redirect()->route('gra-application.create')
                ->with('error', 'Saved your progress, but still missing: '.$missing->implode(', ').'. Upload the rest, then Submit.');
        }

        $engine->submit($application);
        $application->student?->notify(new ApplicationSubmitted($application));

        return redirect()->route('applications.index')
            ->with('status', "GRA application #{$application->id} submitted. Admin has been notified for document verification.");
    }

    /** @return \Illuminate\Support\Collection<int, string> labels of document types with no file attached yet */
    protected function missingRequiredDocuments(Application $application): \Illuminate\Support\Collection
    {
        $attached = ApplicationDocument::where('application_id', $application->id)->pluck('doc_type');

        return collect($this->documentTypes())
            ->reject(fn ($label) => $attached->contains($label))
            ->values();
    }

    /** Extension form: loads the original appointment, asks only for what changes. */
    public function createExtension(Request $request)
    {
        if (! $this->hasAcceptedTerms($request->user())) {
            return redirect()->route('gra-application.terms', ['intent' => 'extension']);
        }

        $profile = GraGaStudentProfile::where('user_id', $request->user()->id)->first();
        abort_unless($profile, 404, 'No existing GRA record found to extend.');

        $parent = Application::where('student_id', $request->user()->id)
            ->where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_APPROVED)
            ->latest('submitted_at')
            ->firstOrFail();

        $parentDetail = GraApplicationDetail::where('application_id', $parent->id)->firstOrFail();

        // "boleh isi bila dah end service date je" — extension only opens
        // once the current appointment has actually run out, 2026-11.
        abort_unless(
            $parentDetail->canBeExtended(),
            403,
            "You can only apply for an extension once your current service period (ending {$parentDetail->service_end_date->format('j M Y')}) has passed."
        );

        return view('haziq::gra_application.form', [
            'profile' => $profile,
            'extension' => true,
            'parent' => $parent,
            'parentDetail' => $parentDetail,
        ]);
    }

    public function storeExtension(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        abort_unless($this->hasAcceptedTerms($request->user()), 403, 'Accept the GRA Scheme Terms and Conditions first.');

        $profile = GraGaStudentProfile::where('user_id', $request->user()->id)->firstOrFail();

        $parent = Application::where('id', $request->integer('parent_application_id'))
            ->where('student_id', $request->user()->id)
            ->where('module_type', $this->moduleKey())
            ->firstOrFail();

        $parentDetail = GraApplicationDetail::where('application_id', $parent->id)->firstOrFail();

        abort_unless(
            $parentDetail->canBeExtended(),
            403,
            "You can only apply for an extension once your current service period (ending {$parentDetail->service_end_date->format('j M Y')}) has passed."
        );

        $data = $request->validate([
            // Section B is re-entered rather than silently copied from the
            // parent appointment — the official Extension Form asks whether
            // the extension is on the same grant or a new one
            // (funding_continuation), and "existing" grant details can
            // genuinely change between appointments (new cost centre, a
            // different project leader, etc.), 2026-11.
            'project_leader_name' => ['required', 'string', 'max:150'],
            'project_leader_contact' => ['nullable', 'string', 'max:30'],
            'project_title' => ['required', 'string', 'max:255'],
            'funding_source' => ['required', 'string', 'max:100'],
            'funding_continuation' => ['required', 'in:new,existing'],
            'grant_name' => ['nullable', 'string', 'max:150'],
            'cost_centre' => ['nullable', 'string', 'max:50'],
            'centre_name' => ['nullable', 'string', 'max:150'],
            'requested_new_end_date' => ['required', 'date', 'after:today'],
            'extension_reason' => ['required', 'string', 'max:2000'],
            // No proposed_allowance here either — same as a new application,
            // CGS sets it when they approve the extension's cgs_review stage.
            'evidence_document' => DocumentStore::rules(),
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents, $profile, $parent, $parentDetail) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            GraApplicationDetail::create([
                'application_id' => $application->id,
                'profile_id' => $profile->id,
                'application_type' => 'extension',
                'parent_application_id' => $parent->id,
                'project_leader_name' => $data['project_leader_name'],
                'project_leader_contact' => $data['project_leader_contact'] ?? null,
                'project_title' => $data['project_title'],
                'funding_source' => $data['funding_source'],
                'funding_continuation' => $data['funding_continuation'],
                'grant_name' => $data['grant_name'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'centre_name' => $data['centre_name'] ?? null,
                'service_start_date' => $parentDetail->service_end_date,
                'service_end_date' => $data['requested_new_end_date'],
                // proposed_allowance stays null — CGS sets it when they approve.
                'previous_allowance' => $parentDetail->proposed_allowance,
                'extension_reason' => $data['extension_reason'],
            ]);

            if ($request->hasFile('evidence_document')) {
                $this->replaceDocument($application, $request->file('evidence_document'), 'Stage Gate Evidence / Publication Proof', $documents);
            }

            return $engine->submit($application);
        });

        $application->student?->notify(new ApplicationSubmitted($application));

        return redirect()->route('applications.index')
            ->with('status', "GRA extension #{$application->id} submitted.");
    }

    /**
     * The student's current 'new' (not extension) GRA application still in
     * flight, if any — pending or already approved. Used to stop a second
     * 'new' application being started while one exists, and to point the
     * sidebar / submission notification at the right one. A rejected
     * application doesn't count — the student is meant to be able to
     * reapply with a fresh one.
     *
     * A Stage-Gate-terminated one, on the other hand, counts the same as
     * an active one now — fixed 2026-10-22, reversing this method's own
     * older comment here that said the opposite. There is no "submit a
     * fresh application to continue" path any more; that was the
     * now-removed TERMINATION_APPEAL_FIELD mechanism. The only way back
     * is CandidatureAppealController appealing this SAME application, so
     * create()/store() redirecting a terminated student to a blank form
     * instead of their own existing one was a real bug this exposed —
     * user: "dekat my application and gra application die cam ada form
     * kosong, kau biar je wujud detail yang student pernah isi." Routing
     * them to showMine() instead shows their real data read-only
     * (canStudentEdit() already returns false while terminated) and
     * becomes editable again on its own once an appeal is approved — no
     * separate "can edit" state to track here.
     */
    protected function activeNewApplication(Request $request): ?Application
    {
        return GraApplicationDetail::where('application_type', 'new')
            ->whereHas('application', fn ($q) => $q
                ->where('student_id', $request->user()->id)
                ->where('module_type', $this->moduleKey())
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED]))
            ->with('application')
            ->latest('id')
            ->first()
            ?->application;
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = GraApplicationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()->keyBy('application_id');

        $openCorrections = ApplicationCorrectionRequest::whereIn('application_id', $queue['applications']->pluck('id'))
            ->whereNull('resolved_at')
            ->get()
            ->groupBy('application_id');

        return view('haziq::gra_application.queue', $queue + [
            'details' => $details,
            'openCorrections' => $openCorrections,
            'documentTypes' => $this->documentTypes(),
            'sectionLabels' => $this->sectionLabels(),
            'sectionFieldLabels' => $this->sectionFieldLabels(),
        ]);
    }

    /**
     * Overrides the trait for three reasons: (1) Admin's stage never
     * rejects outright — a "correction needed" case goes through
     * requestCorrection() instead, so a reject attempt here is refused with
     * a pointer to that; (2) Senior Director's approval is the moment a
     * 'new' application becomes a live GRA record — generate the Stage Gate
     * schedule and the Offer/Admission letters right here, the same pattern
     * CertificationController uses for its own final-stage side effects;
     * (3) advancing to a new stage (without finishing the chain) notifies
     * that stage's role — nothing in Core does this today.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents, StageGateEngine $stageGates): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // A reason is mandatory to reject, optional to approve — the
            // student has to be told why, not just that it happened.
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
        ], [
            'remarks.required_if' => 'A reason is required to reject an application.',
        ]);

        $currentStage = $engine->currentStage($application);

        if ($currentStage?->key === 'admin_verify' && $data['decision'] === 'reject') {
            return back()->with('error', 'Admin does not reject applications — use "Request Correction" on the section or document that needs fixing instead.');
        }

        // CGS sets the GRA allowance the moment they approve their stage —
        // the student never proposes a figure themselves; see
        // gra_application_details' nullable-proposed_allowance migration
        // for why. Validated and saved before engine->decide() runs, so a
        // missing figure blocks the approval rather than leaving it
        // half-applied.
        if ($currentStage?->key === 'cgs_review' && $data['decision'] === 'approve') {
            $allowanceData = $request->validate([
                'proposed_allowance' => ['required', 'numeric', 'min:0'],
            ], [
                'proposed_allowance.required' => 'Set the proposed allowance before approving.',
            ]);

            GraApplicationDetail::where('application_id', $application->id)
                ->update(['proposed_allowance' => $allowanceData['proposed_allowance']]);
        }

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED) {
            $this->onApproved($decided, $request->user(), $documents, $stageGates);
        } elseif ($decided->status === Application::STATUS_REJECTED) {
            // Core's ApplicationDecided (fired inside engine->decide() above)
            // never carries the reason — this is additional, not a
            // replacement, and only touches this module's own notification.
            $application->student?->notify(new ApplicationRejected($application, $currentStage, $data['remarks']));
        } elseif ($decided->status === Application::STATUS_PENDING) {
            $this->notifyNextApprover($decided, $engine);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Supervisor/Senior Director's internal feedback on CGS's recommended
     * allowance — never shown to the student, see
     * AllowanceRecommendationNote's migration for why this is a separate
     * mechanism from "Request Correction". Open to both roles on any GRA
     * application, not gated to their own current stage — same reasoning
     * as "Request Correction" being open regardless of stage, just a
     * narrower audience.
     */
    public function flagAllowance(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $user = $request->user();
        abort_unless(in_array($user->role, [Role::SUPERVISOR, Role::SENIOR_DIRECTOR_CGS], true), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        AllowanceRecommendationNote::create([
            'application_id' => $application->id,
            'author_id' => $user->id,
            'reason' => $data['reason'],
        ]);

        User::whereIn('role', Role::cgsTeam())->get()->each(
            fn (User $cgs) => $cgs->notify(new AllowanceRecommendationFlagged($application, $user, $data['reason']))
        );

        return back()->with('status', 'Noted — CGS has been notified.');
    }

    /**
     * CGS revising the allowance they set — any time the application is
     * still pending, not gated to their own cgs_review decide() the way
     * setting it the first time is, since by the time something's worth
     * revising (most often a Supervisor/Senior Director flag) the
     * application has usually already moved on to a later stage.
     */
    public function editAllowance(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $user = $request->user();
        abort_unless($user->role === Role::NON_EXEC_CGS, 403);
        abort_unless($application->status === Application::STATUS_PENDING, 404, 'This application is no longer pending.');

        $data = $request->validate([
            'proposed_allowance' => ['required', 'numeric', 'min:0'],
        ]);

        GraApplicationDetail::where('application_id', $application->id)
            ->update(['proposed_allowance' => $data['proposed_allowance']]);

        // Whoever flagged it finds out CGS actually looked at it, not just
        // that the number quietly changed underneath them.
        AllowanceRecommendationNote::where('application_id', $application->id)
            ->with('author')->get()->pluck('author')->unique('id')->each(
                fn (User $flagger) => $flagger->notify(new AllowanceRecommendationUpdated($application, (float) $data['proposed_allowance']))
            );

        return back()->with('status', 'Proposed allowance updated.');
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

    protected function onApproved(Application $application, $approver, DocumentStore $documents, StageGateEngine $stageGates): void
    {
        $detail = GraApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);

        if ($detail->isExtension()) {
            $pdf = Pdf::loadView('haziq::gra_application.extension_letter_pdf', [
                'application' => $application, 'detail' => $detail, 'student' => $application->student,
            ]);
            $certificate = $documents->storeGenerated($application, $pdf->output(), "gra-extension-{$application->id}.pdf", 'GRA Extension Approval Letter');
            $application->student?->notify(new LetterIssued($application, $certificate, 'GRA Extension Approval Letter'));

            // The extension moves the appointment's end date; it does not
            // regenerate the Stage Gate schedule, which stays anchored to
            // the original appointment's service_start_date regardless of
            // how many extensions follow it.
            return;
        }

        $stageGates->generateSchedule($application, $profile, $detail->service_start_date);

        foreach (['offer_letter_pdf' => 'GRA Offer Letter', 'admission_letter_pdf' => 'GRA Admission Letter'] as $view => $label) {
            $pdf = Pdf::loadView("haziq::gra_application.{$view}", [
                'application' => $application, 'detail' => $detail, 'profile' => $profile, 'student' => $application->student,
            ]);
            $doc = $documents->storeGenerated($application, $pdf->output(), strtolower(str_replace(' ', '-', $label))."-{$application->id}.pdf", $label);
            $application->student?->notify(new LetterIssued($application, $doc, $label));
        }
    }

    protected function validateApplication(Request $request): array
    {
        return $request->validate([
            'project_leader_name' => ['required', 'string', 'max:150'],
            'project_leader_contact' => ['nullable', 'string', 'max:30'],
            'project_title' => ['required', 'string', 'max:255'],
            'funding_source' => ['required', 'string', 'max:100'],
            'grant_name' => ['nullable', 'string', 'max:150'],
            'cost_centre' => ['nullable', 'string', 'max:50'],
            'centre_name' => ['nullable', 'string', 'max:150'],
            'expected_reporting_date' => ['nullable', 'date'],
            'service_start_date' => ['required', 'date'],
            // No service_end_date, no proposed_allowance here — the first
            // is always derived from service_start_date + level_of_study
            // (24 months Master, 42 PhD; see
            // GraApplicationDetail::computeServiceEndDate()), the second is
            // CGS's to set when they approve, not the student's to propose.

            // Always required, never left to a stale default — every
            // student's own profile is different, and reusing an older
            // application's programme/admission date/bank without asking
            // is exactly the bug this fixes. Pre-filled from the existing
            // profile as a convenience (see form.blade.php), but always a
            // real editable field, validated every time, for every student.
            'level_of_study' => ['required', 'in:master,phd'],
            'programme' => ['required', 'string', 'max:150'],
            'mode_of_study' => ['nullable', 'string', 'max:50'],
            'admission_date' => ['required', 'date'],
            'thesis_title' => ['nullable', 'string', 'max:255'],
            'co_supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'ic_passport_no' => ['nullable', 'string', 'max:50'],
            'visa_expiry_date' => ['nullable', 'date'],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_no' => ['required', 'string', 'max:50'],
            // "Tick if any" on the official form — left blank means none,
            // no separate boolean needed.
            'previous_sponsorship_name' => ['nullable', 'string', 'max:150'],
            'previous_sponsorship_duration' => ['nullable', 'string', 'max:100'],
            'scholarship_name' => ['nullable', 'string', 'max:150'],
            'scholarship_duration' => ['nullable', 'string', 'max:100'],

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
            'publication.*.publication_year' => ['nullable', 'string', 'max:4'],
            'publication.*.journal_or_conference' => ['nullable', 'string', 'max:255'],
            'publication.*.volume_chapter' => ['nullable', 'string', 'max:100'],
            'publication.*.page_number' => ['nullable', 'string', 'max:50'],
            'publication.*.doi' => ['nullable', 'string', 'max:150'],
            'publication.*.issn' => ['nullable', 'string', 'max:50'],
            'publication.*.database_quartile' => ['nullable', 'string', 'max:100'],
            'publication.*.impact_factor' => ['nullable', 'string', 'max:50'],

            // Always nullable here — a document being genuinely required is
            // enforced separately, only at Submit (not Save Draft) time, and
            // only once, by missingRequiredDocuments(), which also checks
            // whatever was already attached from an earlier draft save.
            // DocumentStore::rules(false) === nullable; spelled out for
            // clarity now that there's no conditional driving it.
            'resume' => DocumentStore::rules(),
            'academic_transcript' => DocumentStore::rules(),
            'visa_or_ic' => DocumentStore::rules(),
            'admission_letter' => DocumentStore::rules(),
            'bank_letter' => DocumentStore::rules(),
            'study_plan' => DocumentStore::rules(),
            'grant_utilization_approval' => DocumentStore::rules(),
            'supervisor_supporting_letter' => DocumentStore::rules(),
        ]);
    }

    /**
     * level_of_study/programme/admission_date/bank are always submitted
     * now (see validateApplication()), so an existing profile gets
     * updated with whatever the student entered this time, not silently
     * kept at whatever it was the first time they ever applied — every
     * student's own details, not a frozen default from an old
     * application.
     */
    protected function storeProfile(Request $request, array $data): GraGaStudentProfile
    {
        $attributes = [
            'level_of_study' => $data['level_of_study'],
            'programme' => $data['programme'],
            'mode_of_study' => $data['mode_of_study'] ?? null,
            'admission_date' => $data['admission_date'],
            'thesis_title' => $data['thesis_title'] ?? null,
            'co_supervisor_id' => $data['co_supervisor_id'] ?? null,
            'ic_passport_no' => $data['ic_passport_no'] ?? null,
            'visa_expiry_date' => $data['visa_expiry_date'] ?? null,
            'bank_name' => $data['bank_name'],
            'bank_account_no' => $data['bank_account_no'],
            'previous_sponsorship_name' => $data['previous_sponsorship_name'] ?? null,
            'previous_sponsorship_duration' => $data['previous_sponsorship_duration'] ?? null,
            'scholarship_name' => $data['scholarship_name'] ?? null,
            'scholarship_duration' => $data['scholarship_duration'] ?? null,
        ];

        $existing = GraGaStudentProfile::where('user_id', $request->user()->id)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return GraGaStudentProfile::create(['user_id' => $request->user()->id] + $attributes);
    }

    /**
     * Sections D/E/F are now always on the form (see create()), so this
     * runs for every submission, not just a brand-new student's first one
     * — replace-the-set, not append, same as storeCorrection() already
     * does for these three. Without the delete first, a returning
     * applicant's second GRA application would pile a second copy of
     * their academic/work/publication history on top of the first
     * instead of replacing it.
     */
    protected function storeRepeatables(Request $request, GraGaStudentProfile $profile): void
    {
        AcademicQualification::where('profile_id', $profile->id)->delete();
        foreach ($request->input('academic', []) as $row) {
            if (empty($row['institution'])) {
                continue;
            }
            AcademicQualification::create(['profile_id' => $profile->id] + $row);
        }

        WorkExperience::where('profile_id', $profile->id)->delete();
        foreach ($request->input('experience', []) as $row) {
            if (empty($row['company_name'])) {
                continue;
            }
            WorkExperience::create(['profile_id' => $profile->id] + $row);
        }

        Publication::where('profile_id', $profile->id)->delete();
        foreach ($request->input('publication', []) as $row) {
            if (empty($row['title'])) {
                continue;
            }
            Publication::create(['profile_id' => $profile->id] + $row);
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

    /** The student's view of what Admin flagged — pre-filled edit forms for flagged sections, an upload field for flagged documents. */
    /**
     * The student's edit form — open any time the application is still
     * pending, whether or not an approver has flagged anything. If there
     * are open ApplicationCorrectionRequest rows, the fields/sections/
     * documents they name are highlighted so the student can see exactly
     * what prompted the flag; everything else is still editable, because
     * there's no reason to lock out the rest of the form just because one
     * field was wrong.
     */
    public function showCorrection(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($this->canStudentEdit($application), 404, 'This application can no longer be edited.');

        $open = ApplicationCorrectionRequest::where('application_id', $application->id)->whereNull('resolved_at')->get();

        $detail = GraApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);

        return view('haziq::gra_application.correction', [
            'application' => $application,
            'open' => $open,
            // One flagged field: highlight just that input.
            'flaggedFields' => $open->whereNotNull('target_field')->keyBy(fn ($r) => $r->target_type.'.'.$r->target_field),
            // One flagged section with no specific field named: highlight the whole block.
            'flaggedSections' => $open->whereNull('target_field')->whereIn('target_type', array_keys($this->sectionLabels()))->pluck('target_type'),
            'flaggedDocuments' => $open->where('target_type', 'document')->pluck('target_label'),
            'detail' => $detail,
            'profile' => $profile,
            'academics' => AcademicQualification::where('profile_id', $profile->id)->get(),
            'experiences' => WorkExperience::where('profile_id', $profile->id)->get(),
            'publications' => Publication::where('profile_id', $profile->id)->get(),
            'documentTypes' => $this->documentTypes(),
            // What's already on file, so the form shows "already uploaded:
            // X.pdf" instead of a blank input that looks like everything
            // vanished — a file input can never be pre-filled with an
            // existing upload, only told what's already there.
            'existingDocuments' => ApplicationDocument::where('application_id', $application->id)->get()->keyBy('doc_type'),
            'supervisors' => User::where('role', Role::SUPERVISOR)->orderBy('name')->get(),
        ]);
    }

    /**
     * The form always submits every section now (not only flagged ones —
     * the student can edit anything, any time), so this always validates
     * and saves all of them, then resolves whatever was open. Resolving
     * everything open on one submission — rather than tracking which
     * specific flagged item each field change addressed — matches "the
     * student resubmits the application", not a checklist worked one item
     * at a time.
     */
    public function storeCorrection(Request $request, Application $application, DocumentStore $documents, StageGateEngine $stageGates): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($this->canStudentEdit($application), 404, 'This application can no longer be edited.');

        $open = ApplicationCorrectionRequest::where('application_id', $application->id)->whereNull('resolved_at')->get();

        $detail = GraApplicationDetail::where('application_id', $application->id)->firstOrFail();
        $profile = GraGaStudentProfile::findOrFail($detail->profile_id);
        $serviceStartDateBefore = $detail->service_start_date;

        DB::transaction(function () use ($request, $application, $documents, $detail, $profile) {
            // Not an extension — an extension's service_start_date follows
            // its parent appointment automatically and is never a form
            // field here (see correction.blade.php), so it's never
            // validated or touched for one.
            $isExtension = $detail->isExtension();

            $detail->update($request->validate([
                'project_leader_name' => ['required', 'string', 'max:150'],
                'project_leader_contact' => ['nullable', 'string', 'max:30'],
                'project_title' => ['required', 'string', 'max:255'],
                'funding_source' => ['required', 'string', 'max:100'],
                'grant_name' => ['nullable', 'string', 'max:150'],
                'cost_centre' => ['nullable', 'string', 'max:50'],
                'centre_name' => ['nullable', 'string', 'max:150'],
                // Only an extension row ever collects a funding_continuation
                // in the first place (see storeExtension() vs store()).
                'funding_continuation' => $isExtension ? ['required', 'in:new,existing'] : ['prohibited'],
                // No service_end_date, no proposed_allowance — same reasons
                // as the submission form: the first is always derived below,
                // the second stays CGS's to set.
                'service_start_date' => $isExtension ? ['prohibited'] : ['required', 'date'],
            ]));

            $profile->update($request->validate([
                'level_of_study' => ['required', 'in:master,phd'],
                'programme' => ['required', 'string', 'max:150'],
                'mode_of_study' => ['nullable', 'string', 'max:50'],
                'admission_date' => ['required', 'date'],
                'thesis_title' => ['nullable', 'string', 'max:255'],
                'co_supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
                'ic_passport_no' => ['nullable', 'string', 'max:50'],
                'bank_name' => ['nullable', 'string', 'max:100'],
                'bank_account_no' => ['nullable', 'string', 'max:50'],
                'previous_sponsorship_name' => ['nullable', 'string', 'max:150'],
                'previous_sponsorship_duration' => ['nullable', 'string', 'max:100'],
                'scholarship_name' => ['nullable', 'string', 'max:150'],
                'scholarship_duration' => ['nullable', 'string', 'max:100'],
            ]));

            // An extension's service_end_date is its own custom, previously
            // requested date — never auto-derived; see
            // GraApplicationDetail::computeServiceEndDate().
            if (! $isExtension) {
                $detail->update([
                    'service_end_date' => GraApplicationDetail::computeServiceEndDate($detail->service_start_date, $profile->level_of_study),
                ]);
            }

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

            $request->validate([
                'publication.*.title' => ['nullable', 'string', 'max:255'],
                'publication.*.publication_year' => ['nullable', 'string', 'max:4'],
                'publication.*.volume_chapter' => ['nullable', 'string', 'max:100'],
                'publication.*.page_number' => ['nullable', 'string', 'max:50'],
                'publication.*.doi' => ['nullable', 'string', 'max:150'],
                'publication.*.issn' => ['nullable', 'string', 'max:50'],
                'publication.*.database_quartile' => ['nullable', 'string', 'max:100'],
                'publication.*.impact_factor' => ['nullable', 'string', 'max:50'],
            ]);
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
        });

        // service_start_date changing is the one edit the Stage Gate
        // schedule actually cares about — recompute this application's
        // gates right away from the corrected date rather than leaving
        // them stale until the next daily stage-gate:evaluate run.
        // recomputeApplication() (not evaluateApplication()) on purpose:
        // a student's own correction must be believed even for a gate
        // that's already withheld/forfeited under the old, wrong date —
        // see StageGateEngine's class docblock. Compares against the
        // value read before the transaction, not $detail->isDirty() (the
        // model was updated via a fresh ->update() call on a re-fetched
        // instance inside the closure, so dirty-tracking on this outer
        // $detail reference can't be trusted).
        $detail->refresh();
        if (! $serviceStartDateBefore->equalTo($detail->service_start_date)) {
            $stageGates->recomputeApplication($application, $detail->service_start_date);
        }

        // Notify whoever actually asked for each fix, if anyone did — a
        // self-initiated edit with nothing flagged notifies nobody, since
        // $open is simply empty and notifyRequesters() on an empty
        // collection is a no-op.
        $this->notifyRequesters($open, $application);

        return redirect()->route('applications.index')
            ->with('status', $open->isNotEmpty()
                ? 'Corrections submitted. The approver(s) who flagged them have been notified.'
                : 'Application updated.');
    }

    /** @param  \Illuminate\Support\Collection<int, ApplicationCorrectionRequest>  $resolved */
    protected function notifyRequesters($resolved, Application $application): void
    {
        $requesterIds = $resolved->pluck('requested_by')->unique();

        User::whereIn('id', $requesterIds)->get()->each(
            fn (User $requester) => $requester->notify(new ApplicationCorrectionResubmitted($application))
        );
    }
}
