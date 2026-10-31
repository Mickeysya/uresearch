<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationBlock;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Chloe\Support\WorkstationLiveVersion;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Home (choose block) -> Room (choose room, filtered by the student's own
 * gender) -> Seat map, the same hierarchy as CGS's own seat map reference.
 *
 * `users` carries no gender column, and Hard Rule 2 forbids adding one
 * there, so a student's gender for room-eligibility purposes lives in this
 * module's own `student_genders` table instead -- the only gender column
 * anywhere in this database. It is read-only everywhere in this module: no
 * screen, student or CGS, can set or change it (there is no route for it
 * anywhere); it is set only by DatabaseSeeder's demo data, or directly in
 * the database. A student with no row yet sees a "contact CGS" message
 * rather than a self-service picker. See genderFor().
 */
class WorkstationController extends Controller
{
    public function index(Request $request)
    {
        WorkstationLiveVersion::begin($request->user());
        $activeRequest = $this->activeRequestFor($request);

        // Rule 11: the reminder/auto-cancel thresholds are checked here too,
        // not just by the daily workstation:remind-locker-keys sweep, so a
        // student's own status is never stale between scheduler runs. Scoped
        // to this one student's own key, not a table-wide sweep — see
        // LockerKey::processIfOverdue().
        if ($activeRequest?->lockerKey?->status === LockerKey::STATUS_REQUESTED) {
            if ($activeRequest->lockerKey->processIfOverdue()) {
                $activeRequest->unsetRelation('lockerKey');
            }
        }

        // STEP 5: Return Pending shows even with no current seat -- the
        // student returned/was released from the seat the key still
        // belongs to, so it can't be found through activeRequest's own
        // lockerKey relation (that only looks at a CONFIRMED request).
        $returnPendingKey = LockerKey::where('student_id', $request->user()->id)
            ->where('status', LockerKey::STATUS_RETURN_PENDING)
            ->latest('requested_at')
            ->first();

        // PART 2: the loan period's own end date, and PART 3's banner for
        // a seat already mid-notice -- both only mean anything with a
        // seat currently held, so both stay null without one.
        $loanValidUntil = null;
        $terminationNotice = null;

        if ($activeRequest) {
            $loanValidUntil = StudyCandidacy::where('student_id', $request->user()->id)
                ->value('candidacy_expiry_date');
            $terminationNotice = $activeRequest->activeTerminationNotice;
        }

        $gender = $this->genderFor($request);

        $blockCodes = WorkstationLocation::query()
            ->select('block')
            ->distinct()
            ->orderBy('block')
            ->pluck('block');

        // Every block a room actually sits in, including one that was added
        // to the catalogue but never got its own WorkstationBlock row yet —
        // it still gets a card, just with the generic photo, rather than
        // silently vanishing from Home.
        $known = WorkstationBlock::whereIn('code', $blockCodes)->get()->keyBy('code');
        $blocks = $blockCodes->map(fn ($code) => $known->get($code) ?? new WorkstationBlock(['code' => $code, 'name' => "Block {$code}"]));

        return view('chloe::workstation.select', compact('activeRequest', 'blocks', 'gender', 'returnPendingKey', 'loanValidUntil', 'terminationNotice'));
    }

    public function rooms(Request $request, string $block, WorkstationAllocator $allocator)
    {
        // Checked here too, not just by the scheduled command, so a hold
        // that expired seconds ago doesn't still show as taken.
        $allocator->releaseExpiredSelections();

        $activeRequest = $this->activeRequestFor($request);
        $gender = $this->genderFor($request);

        if (! $gender) {
            return redirect()->route('workstation.select')
                ->with('error', 'Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).');
        }

        if ($ineligible = $this->ineligibilityErrorFor($request)) {
            return redirect()->route('workstation.select')->with('error', $ineligible);
        }

        $rooms = WorkstationLocation::where('block', $block)
            ->where('gender', $gender)
            ->withCount([
                'workstations',
                'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
            ])
            ->orderBy('room_code')
            ->get();

        abort_if($rooms->isEmpty(), 404);

        return view('chloe::workstation.rooms', compact('block', 'rooms', 'activeRequest'));
    }

    public function seats(Request $request, WorkstationLocation $workstationLocation, WorkstationAllocator $allocator)
    {
        $allocator->releaseExpiredSelections();

        $activeRequest = $this->activeRequestFor($request);
        $gender = $this->genderFor($request);

        if (! $gender) {
            return redirect()->route('workstation.select')
                ->with('error', 'Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).');
        }

        if ($ineligible = $this->ineligibilityErrorFor($request)) {
            return redirect()->route('workstation.select')->with('error', $ineligible);
        }

        // Belt-and-suspenders against a direct URL to a room of the other
        // designation — the room list above already filters, but a link or a
        // stale bookmark could still point here.
        abort_if($workstationLocation->gender !== $gender, 403);

        // The seat list page is a flat list now, not a seat map (Room
        // Overview was removed), so seats belong in plain ascending seat
        // number order, not the room's physical reading order (`position`,
        // left over from that map). seat_code is a string column -- several
        // rooms' numbering isn't padded -- so a plain ORDER BY would sort
        // "100" before "99"; CAST to an integer first. `workstations()` is
        // already scoped to this room via workstation_location_id, so a
        // room can never surface another room's seat.
        $availableSeats = $workstationLocation->workstations()
            ->where('status', Workstation::STATUS_AVAILABLE)
            ->orderByRaw('CAST(seat_code AS UNSIGNED) ASC')
            ->get();

        return view('chloe::workstation.seats', compact('workstationLocation', 'availableSeats', 'activeRequest'));
    }

    public function store(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $allocator->releaseExpiredSelections();

        $student = $request->user();
        $gender = $this->genderFor($request);

        // Belt-and-suspenders, same as seats() above — the list a student
        // picked this seat from is already filtered, but the POST target
        // (a plain Workstation id) carries no such guarantee on its own.
        abort_if($workstation->location->gender !== $gender, 403);

        if ($ineligible = $this->ineligibilityErrorFor($request)) {
            return redirect()->route('workstation.seats', $workstation->workstation_location_id)->with('error', $ineligible);
        }

        if ($this->hasAnActiveSelection($student->id)) {
            return redirect()->route('workstation.seats', $workstation->workstation_location_id)
                ->with('error', 'You already have a workstation, or a selection awaiting confirmation. Release it, or confirm it from your email, before selecting another.');
        }

        $result = $allocator->beginPendingSelection($student, $workstation);

        if (! $result['started']) {
            return redirect()->route('workstation.seats', $workstation->workstation_location_id)
                ->with('error', 'This seat is no longer available. Please select another seat.');
        }

        return redirect()->route('workstation.seats', $workstation->workstation_location_id)
            ->with('status', 'A confirmation email has been sent to your email. Please confirm within 5 minutes to secure your seat.');
    }

    /**
     * The link in Email 1. Deliberately reachable without being logged in
     * as the selecting student — the token itself, not the session, is the
     * proof of authorisation (the same shape as a password-reset link), so
     * the seat goes to the token's own student_id regardless of who is
     * signed in on the device the link is opened on, or whether anyone is.
     */
    public function confirm(Request $request, string $token, WorkstationAllocator $allocator)
    {
        $allocator->releaseExpiredSelections();

        $selection = WorkstationPendingSelection::where('token', $token)->first();

        if (! $selection) {
            return view('chloe::workstation.confirm', ['outcome' => 'invalid']);
        }

        if ($selection->status === WorkstationPendingSelection::STATUS_CONFIRMED) {
            return view('chloe::workstation.confirm', ['outcome' => 'already-confirmed', 'workstation' => $selection->workstation]);
        }

        $result = $allocator->confirmPendingSelection($selection);

        if (! $result['confirmed']) {
            $outcome = $result['already'] === 'expired' ? 'expired' : 'invalid';

            return view('chloe::workstation.confirm', ['outcome' => $outcome]);
        }

        return view('chloe::workstation.confirm', ['outcome' => 'confirmed', 'workstation' => $result['workstation']]);
    }

    protected function hasAnActiveSelection(int $studentId): bool
    {
        $hasConfirmedSeat = WorkstationRequest::where('student_id', $studentId)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->exists();

        $hasPendingSelection = WorkstationPendingSelection::where('student_id', $studentId)
            ->where('status', WorkstationPendingSelection::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();

        return $hasConfirmedSeat || $hasPendingSelection;
    }

    /**
     * STEP 5: no confirmation dialog on purpose — the reason dropdown
     * itself is the deliberate step (the button stays disabled until one
     * is chosen; see select.blade.php), not a second "are you sure".
     */
    public function returnWorkstation(Request $request, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_STUDENT_RETURN)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $activeRequest = WorkstationRequest::where('student_id', $request->user()->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if (! $activeRequest) {
            return redirect()->route('workstation.select')
                ->with('error', 'You do not currently hold a workstation.');
        }

        $allocator->releaseSeat(
            $activeRequest,
            WorkstationHistory::ACTOR_STUDENT,
            $request->user(),
            $data['reason'],
            $data['remark'] ?? null,
        );

        return redirect()->route('workstation.select')
            ->with('status', 'Workstation returned.');
    }

    protected function activeRequestFor(Request $request): ?WorkstationRequest
    {
        return WorkstationRequest::with('workstation.location', 'lockerKey')
            ->where('student_id', $request->user()->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();
    }

    protected function genderFor(Request $request): ?string
    {
        return StudentGender::where('student_id', $request->user()->id)->value('gender');
    }

    /** PART 1: null when eligible, the exact message to show otherwise. */
    protected function ineligibilityErrorFor(Request $request): ?string
    {
        $identity = StudentDirectory::fromUser($request->user());

        return WorkstationEligibility::isEligible($identity) ? null : WorkstationEligibility::INELIGIBLE_MESSAGE;
    }
}
