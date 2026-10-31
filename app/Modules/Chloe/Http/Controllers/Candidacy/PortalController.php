<?php

namespace App\Modules\Chloe\Http\Controllers\Candidacy;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Services\CandidacySignatureService;
use App\Modules\Chloe\Support\CandidacyAppealPresenter;
use App\Modules\Chloe\Support\CandidacyLiveVersion;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Approval Portal: one queue, one review page and one signature page for Supervisor, Chair, CGS and Dean. */
class PortalController extends Controller
{
    public function __construct(
        protected CandidacyAppealService $service,
        protected CandidacySignatureService $signatures,
    ) {}

    // ------------------------------------------------------------ queue

    public function queue(Request $request)
    {
        return view('chloe::candidacy.wca.portal.queue', $this->queueData($request));
    }

    public function queueRegion(Request $request)
    {
        return view('chloe::candidacy.wca.portal._queue_region', $this->queueData($request));
    }

    /** @return array<string, mixed> */
    protected function queueData(Request $request): array
    {
        $user = $request->user();
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'waiting';
        $filters = [
            'q' => $request->query('q'), 'department' => $request->integer('department') ?: null, 'months' => $request->integer('months') ?: null,
            'waiting' => $request->integer('waiting') ?: null, 'round' => $request->integer('round') ?: null,
        ];

        $base = $tab === 'waiting' ? CandidacyPortalQuery::waiting($user) : CandidacyPortalQuery::decided($user);
        $rows = CandidacyPortalQuery::filter($base, $filters)
            ->with(['student', 'demoProfile', 'department', 'approvals'])
            ->orderByRaw($tab === 'waiting' ? 'stage_entered_at asc' : 'COALESCE(last_action_at, updated_at) desc')
            ->get();

        return [
            'tab' => $tab, 'rows' => $rows, 'filters' => $filters, 'user' => $user,
            'waitingCount' => CandidacyPortalQuery::waiting($user)->count(),
            'decidedCount' => CandidacyPortalQuery::decided($user)->count(),
            'departments' => CandidacyDepartment::orderBy('name')->get(),
            'liveVersion' => CandidacyPortalQuery::version($user),
            'query' => array_filter($request->only(['tab', 'q', 'department', 'months', 'waiting', 'round'])),
        ];
    }

    public function queueVersion(Request $request)
    {
        return response()->json(['version' => CandidacyPortalQuery::version($request->user())]);
    }

    // ------------------------------------------------------------ review and decide

    public function review(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('view', $appeal);

        return view('chloe::candidacy.wca.portal.review', $this->reviewData($request, $appeal));
    }

    public function reviewRegion(Request $request, CandidacyAppeal $appeal)
    {
        Gate::authorize('view', $appeal);

        return view('chloe::candidacy.wca.portal._review_region', $this->reviewData($request, $appeal));
    }

    /** @return array<string, mixed> */
    protected function reviewData(Request $request, CandidacyAppeal $appeal): array
    {
        $data = app(StudentAppealController::class)->detailData($request, $appeal);
        $user = $request->user();
        $canAct = $user->can('act', $appeal);
        $stage = $appeal->current_stage;
        $profile = $stage && $stage !== 'cgs' ? match ($stage) { 'supervisor' => $appeal->supervisorProfile, 'chair' => $appeal->chairProfile, default => $appeal->deanProfile } : CandidacyStaffDirectory::profileForUser($user);
        $candidacy = $appeal->candidacy;
        $months = (int) $appeal->requested_months;

        return $data + [
            'portal' => true, 'canAct' => $canAct, 'stage' => $stage,
            'needsSignature' => $canAct && in_array($stage, (array) config('candidacy.signature_required_stages'), true) && ! $profile?->hasSignature(),
            'returnReasons' => (array) config("candidacy.reasons.{$stage}.return", []),
            'rejectReasons' => (array) config("candidacy.reasons.{$stage}.reject", []),
            'earlier' => CandidacyAppeal::where('student_id', $appeal->student_id)->where('id', '!=', $appeal->id)->whereNotNull('appeal_no')->orderByDesc('id')->get(),
            'preview' => $candidacy && $months > 0 ? [
                'new_expiry' => $candidacy->candidacy_expiry_date->copy()->addMonthsNoOverflow($months),
                'allowance_after' => $candidacy->allowanceUsed() + $months,
            ] : null,
        ];
    }

    public function decide(Request $request, CandidacyAppeal $appeal)
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,verify,return,reject'],
            'reasons' => ['nullable', 'array'], 'reasons.*' => ['string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'documents_complete' => ['nullable', 'in:yes,no'],
        ]);

        $done = $this->service->decide(
            $request->user(), $appeal, $data['action'], $data['reasons'] ?? [], $data['remark'] ?? null,
            isset($data['documents_complete']) ? $data['documents_complete'] === 'yes' : null,
        );

        $verb = ['approve' => 'approved', 'verify' => 'verified', 'return' => 'returned to the student', 'reject' => 'rejected (final)'][$data['action']];

        return redirect()->route('candidacy.portal.queue')->with('wca_status', "Appeal {$done->appeal_no} was {$verb}.");
    }

    // ------------------------------------------------------------ My Signature

    public function signature(Request $request)
    {
        $profile = CandidacyStaffDirectory::profileForUser($request->user());

        return view('chloe::candidacy.wca.portal.signature', ['profile' => $profile, 'user' => $request->user(), 'required' => $this->signatureRequired($request->user())]);
    }

    public function signatureStore(Request $request)
    {
        $request->validate(['signature' => ['required', 'file']], ['signature.required' => 'Choose an image file (PNG or JPG).']);
        $profile = $this->ensureProfile($request->user());
        Gate::authorize('viewSignature', $profile);

        $this->signatures->store($profile, $request->file('signature'), $request->user());

        return redirect()->route('candidacy.signature.show')->with('wca_status', 'Your signature was saved.');
    }

    public function signatureDestroy(Request $request)
    {
        $profile = CandidacyStaffDirectory::profileForUser($request->user());
        abort_unless($profile, 404);
        Gate::authorize('viewSignature', $profile);

        $this->signatures->remove($profile, $request->user());

        return redirect()->route('candidacy.signature.show')->with('wca_status', 'Your signature was removed.');
    }

    public function signatureImage(Request $request): StreamedResponse
    {
        $profile = CandidacyStaffDirectory::profileForUser($request->user());
        abort_unless($profile && $profile->signature_path && Storage::disk('local')->exists($profile->signature_path), 404);
        Gate::authorize('viewSignature', $profile);

        return Storage::disk('local')->response($profile->signature_path, null, ['Content-Type' => 'image/png']);
    }

    protected function signatureRequired(User $user): bool
    {
        return in_array($user->role, [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], true);
    }

    protected function ensureProfile(User $user): CandidacyStaffProfile
    {
        return CandidacyStaffDirectory::profileForUser($user) ?? CandidacyStaffProfile::create([
            'user_id' => $user->id, 'full_name' => $user->name,
            'designation' => match ($user->role) {
                Role::SUPERVISOR => 'Lecturer', Role::CHAIR => 'Programme Chair', Role::DEAN_PGR => 'Dean of Postgraduate and Research',
                default => 'Centre for Graduate Studies (CGS)',
            },
        ]);
    }
}
