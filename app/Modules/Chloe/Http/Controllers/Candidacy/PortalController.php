<?php

namespace App\Modules\Chloe\Http\Controllers\Candidacy;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Services\CandidacySignatureService;
use App\Modules\Chloe\Support\CandidacyAppealPresenter;
use App\Modules\Chloe\Support\CandidacyLiveVersion;
use App\Modules\Chloe\Support\CandidacyPagination as Pg;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyReturnPath;
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
    public const PER_PAGE = 25;

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
        $tabs = CandidacyPortalQuery::tabs($user);
        $tab = in_array($request->query('tab'), $tabs, true) ? $request->query('tab') : 'pending';
        $filters = [
            'q' => $request->query('q') ? mb_substr(trim((string) $request->query('q')), 0, 100) : null, 'department' => $request->integer('department') ?: null,
            'months' => $request->integer('months') ?: null, 'waiting' => $request->integer('waiting') ?: null, 'round' => $request->integer('round') ?: null,
        ];

        $listQuery = CandidacyPortalQuery::filter(CandidacyPortalQuery::forTab($user, $tab), $filters);
        $total = (clone $listQuery)->count();
        $pages = Pg::pages($total, self::PER_PAGE);
        $page = Pg::page($request->query('page'), $pages); // 0 / "abc" -> 1; beyond the last page -> the last page

        $rows = $listQuery->with(['student', 'demoProfile', 'department', 'approvals', 'supervisorProfile', 'chairProfile', 'deanProfile'])
            ->orderByRaw($tab === 'pending' ? 'stage_entered_at asc' : 'COALESCE(last_action_at, updated_at) desc')
            ->orderBy('id')
            ->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get();

        $query = array_filter(['tab' => $tab === 'pending' ? null : $tab, 'q' => $filters['q'], 'department' => $filters['department'], 'months' => $filters['months'], 'waiting' => $filters['waiting'], 'round' => $filters['round'], 'page' => $page > 1 ? $page : null]);

        return [
            'page' => $page, 'pages' => $pages, 'total' => $total, 'perPage' => self::PER_PAGE,
            'tab' => $tab, 'tabs' => $tabs, 'rows' => $rows, 'filters' => $filters, 'user' => $user, 'isCgs' => CandidacyPortalQuery::isCgs($user),
            'counts' => CandidacyPortalQuery::counts($user),
            'departments' => CandidacyDepartment::orderBy('name')->get(),
            'liveVersion' => CandidacyPortalQuery::version($user),
            'query' => $query,
            'returnTo' => CandidacyReturnPath::current($request, '/candidacy/portal', $query),
        ];
    }

    public function queueVersion(Request $request)
    {
        return response()->json(['version' => CandidacyPortalQuery::version($request->user()), 'counts' => CandidacyPortalQuery::counts($request->user())]);
    }

    // ------------------------------------------------------------ decide (the page itself is the shared appeal page)

    public function decide(Request $request, CandidacyAppeal $appeal)
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,verify,return,reject'],
            'reasons' => ['nullable', 'array'], 'reasons.*' => ['string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'documents_complete' => ['nullable', 'in:yes,no'],
            'approved_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'adjust_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $done = $this->service->decide(
            $request->user(), $appeal, $data['action'], $data['reasons'] ?? [], $data['remark'] ?? null,
            isset($data['documents_complete']) ? $data['documents_complete'] === 'yes' : null,
            isset($data['approved_months']) ? (int) $data['approved_months'] : null, $data['adjust_reason'] ?? null,
        );

        $verb = ['approve' => 'approved', 'verify' => 'verified', 'return' => 'returned to the student', 'reject' => 'rejected (final)'][$data['action']];

        return redirect(CandidacyReturnPath::resolve($request, route('candidacy.portal.queue', [], false)))->with('wca_status', "Appeal {$done->appeal_no} was {$verb}.");
    }

    // ------------------------------------------------------------ My Signature

    public function signature(Request $request)
    {
        // There is one signature page now, Core's "My Signatures", with this
        // module's signature as its "candidacy" section. The validated return
        // path rides along, so Back and an upload there still come back to the
        // appeal or queue the user left; reflash() keeps any message.
        $return = CandidacyReturnPath::validate($request->query('return'));
        $request->session()->reflash();

        return redirect()->to(route('signatures.index', array_filter(['return' => $return])).'#candidacy');
    }

    public function signatureStore(Request $request)
    {
        $request->validate(['signature' => ['required', 'file']], ['signature.required' => 'Choose an image file (PNG or JPG).']);
        $profile = $this->ensureProfile($request->user());
        Gate::authorize('viewSignature', $profile);

        $this->signatures->store($profile, $request->file('signature'), $request->user());

        return redirect($this->afterSignature($request))->with('wca_status', 'Your signature was saved.');
    }

    public function signatureDestroy(Request $request)
    {
        $profile = CandidacyStaffDirectory::profileForUser($request->user());
        abort_unless($profile, 404);
        Gate::authorize('viewSignature', $profile);

        $this->signatures->remove($profile, $request->user());

        return redirect($this->afterSignature($request))->with('wca_status', 'Your signature was removed.');
    }

    public function signatureImage(Request $request): StreamedResponse
    {
        $profile = CandidacyStaffDirectory::profileForUser($request->user());
        abort_unless($profile && $profile->signature_path && Storage::disk('local')->exists($profile->signature_path), 404);
        Gate::authorize('viewSignature', $profile);

        return Storage::disk('local')->response($profile->signature_path, null, ['Content-Type' => 'image/png']);
    }

    /** Back to the appeal or portal page the user came from (validated, internal only); from the sidebar it stays on this page. */
    protected function afterSignature(Request $request): string
    {
        return CandidacyReturnPath::validate($request->input('return')) ?? route('candidacy.signature.show', [], false);
    }


    protected function ensureProfile(User $user): CandidacyStaffProfile
    {
        return CandidacyStaffDirectory::profileForUser($user) ?? CandidacyStaffProfile::create([
            'user_id' => $user->id, 'full_name' => $user->name,
            'designation' => match ($user->role) {
                Role::SUPERVISOR => 'Lecturer', Role::CHAIR => 'Department Chair', Role::DEAN_PGR => 'Dean of Postgraduate and Research',
                default => 'Centre for Graduate Studies (CGS)',
            },
        ]);
    }
}
