<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Exports\WorkstationHistoryExport;
use App\Modules\Chloe\Exports\WorkstationSeatsExport;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationBlock;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Support\SeatOrder;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentIdentity;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Chloe\Support\WorkstationLiveVersion;
use App\Modules\Chloe\Support\WorkstationStatistics;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * The CGS staff screens: search/filter the whole catalogue, drill
 * Block -> Room -> Seat, and act on one seat at a time. Every action here is
 * a thin wrapper around WorkstationAllocator's five STEP 2 methods (or
 * LockerKey's own markAsCollected()/markReturned()) -- this controller picks
 * the seat/student and validates the reason, the allocator is still the
 * only code that writes a seat's status, a History row, and a key
 * transition together. Nothing here duplicates that logic.
 *
 * Replaces WorkstationOperationsController::index() and the force-assign/
 * force-release/mark-collected/mark-returned forms on the old operations
 * view -- see that controller's class doc for what happened to each of
 * those old actions.
 */
class WorkstationManagementController extends Controller
{
    private const PER_PAGE = 24;

    /** PART 3 #4: a Seat Status filter value, not a Workstation::STATUS_* -- the seat itself stays Occupied. */
    public const SEAT_STATUS_TERMINATION_NOTICE = 'termination_notice';

    private const KEY_LABELS = [
        LockerKey::STATUS_REQUESTED => 'Pending Collection',
        LockerKey::STATUS_COLLECTED => 'Collected',
        LockerKey::STATUS_RETURN_PENDING => 'Return Pending',
    ];

    public function index(Request $request)
    {
        WorkstationLiveVersion::begin();
        $filters = $this->filtersFrom($request);
        $stats = $this->stats();
        $locations = WorkstationLocation::orderBy('block')->orderBy('room_code')->get();

        if (! $this->hasActiveFilters($filters)) {
            $blockCodes = WorkstationLocation::query()->select('block')->distinct()->orderBy('block')->pluck('block');
            $known = WorkstationBlock::whereIn('code', $blockCodes)->get()->keyBy('code');
            $blocks = $blockCodes->map(fn ($code) => $known->get($code) ?? new WorkstationBlock(['code' => $code, 'name' => "Block {$code}"]));

            return view('chloe::workstation.cgs.operations', [
                'mode' => 'browse',
                'blocks' => $blocks,
                'stats' => $stats,
                'filters' => $filters,
                'locations' => $locations,
            ]);
        }

        $allRows = $this->seatRows($filters);
        $page = max(1, (int) $request->query('page', 1));
        $seats = new LengthAwarePaginator(
            $allRows->forPage($page, self::PER_PAGE)->values(),
            $allRows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('chloe::workstation.cgs.operations', [
            'mode' => 'results',
            'seats' => $seats,
            'stats' => $stats,
            'filters' => $filters,
            'locations' => $locations,
        ]);
    }

    public function block(string $block)
    {
        WorkstationLiveVersion::begin();
        $rooms = WorkstationStatistics::withSeatCounts(WorkstationLocation::where('block', $block))
            ->orderBy('room_code')
            ->get();

        abort_if($rooms->isEmpty(), 404);

        return view('chloe::workstation.cgs.block', compact('block', 'rooms'));
    }

    public function room(WorkstationLocation $workstationLocation)
    {
        WorkstationLiveVersion::begin();
        $seats = $workstationLocation->workstations()
            ->with(['activeRequest.student', 'activeRequest.demoProfile', 'activeRequest.lockerKey', 'activeRequest.activeTerminationNotice', 'lockerKeysHistory.student', 'lockerKeysHistory.demoProfile'])
            ->tap(fn ($q) => SeatOrder::query($q))
            ->get()
            ->map(fn ($seat) => $this->presentSeat($seat));

        return view('chloe::workstation.cgs.room', compact('workstationLocation', 'seats'));
    }

    public function show(Workstation $workstation)
    {
        WorkstationLiveVersion::begin();
        $workstation->load(['location', 'activeRequest.student', 'activeRequest.demoProfile', 'activeRequest.lockerKey', 'activeRequest.activeTerminationNotice', 'lockerKeysHistory.student', 'lockerKeysHistory.demoProfile']);

        $row = $this->presentSeat($workstation);
        $occupant = $workstation->activeRequest?->occupant();
        $terminationNotice = $workstation->activeRequest?->activeTerminationNotice;

        // The raw LockerKey (not presentSeat()'s pre-formatted summary) --
        // the details page needs every date field individually, plus to
        // know exactly which of markAsCollected()/markReturned() its own
        // buttons should offer. Falls back to the latest Lost key too
        // (PART 5) -- the one other terminal state worth still showing
        // right after a Release Hold, when the seat has already gone back
        // to Available and there is no Return Pending key left to find.
        $lockerKey = $workstation->activeRequest?->lockerKey
            ?: $workstation->lockerKeysHistory->filter(fn ($key) => $key->status === LockerKey::STATUS_RETURN_PENDING)->sortByDesc('requested_at')->first()
            ?: $workstation->lockerKeysHistory->filter(fn ($key) => $key->status === LockerKey::STATUS_LOST)->sortByDesc('requested_at')->first();

        $history = WorkstationHistory::where('block', $workstation->location->block)
            ->where('room_code', $workstation->location->room_code)
            ->where('seat_number', $workstation->seat_code)
            ->orderByDesc('created_at')
            ->get();

        $availableSeatsForReassign = collect();

        if ($workstation->status === Workstation::STATUS_OCCUPIED && $occupant) {
            $availableSeatsForReassign = SeatOrder::sort(
                Workstation::where('status', Workstation::STATUS_AVAILABLE)
                    ->whereHas('location', fn ($q) => $q->where('gender', $occupant->gender))
                    ->with('location')
                    ->get(),
                fn ($seat) => [$seat->location->block, $seat->location->room_code, $seat->seat_code, $seat->id]
            );
        }

        // PART 2 #1: no current occupant but a Return Pending key (always
        // true for Awaiting Key Return; also covers a plain Available seat
        // whose key has not been resolved yet) -- who held it, and when
        // they left.
        $previousOccupant = null;
        $previousOccupantReleasedAt = null;

        if (! $occupant && $lockerKey?->status === LockerKey::STATUS_RETURN_PENDING) {
            $previousOccupant = $lockerKey->occupant();
            $previousOccupantReleasedAt = $lockerKey->workstationRequest->released_at;
        }

        // PART 2 #2: the CURRENT occupant may still owe a key from an
        // earlier seat -- a standing obligation that follows the student,
        // not the seat, so it has to be looked up independently of
        // whichever key (if any) belongs to THIS seat.
        $elsewhereReturnPending = null;

        if ($occupant) {
            $elsewhereKey = LockerKey::where('status', LockerKey::STATUS_RETURN_PENDING)
                ->when($occupant->user, fn ($q) => $q->where('student_id', $occupant->user->id))
                ->when($occupant->demoProfile, fn ($q) => $q->where('demo_profile_id', $occupant->demoProfile->id))
                ->with('workstationRequest.workstation')
                ->first();

            if ($elsewhereKey && $elsewhereKey->workstationRequest->workstation_id !== $workstation->id) {
                $elsewhereReturnPending = [
                    'seat_code' => $elsewhereKey->workstationRequest->workstation->seat_code,
                    'return_due_at' => $elsewhereKey->return_due_at,
                ];
            }
        }

        return view('chloe::workstation.cgs.seat-details', [
            'workstation' => $workstation,
            'row' => $row,
            'occupant' => $occupant,
            'lockerKey' => $lockerKey,
            'history' => $history,
            'availableSeatsForReassign' => $availableSeatsForReassign,
            'previousOccupant' => $previousOccupant,
            'previousOccupantReleasedAt' => $previousOccupantReleasedAt,
            'elsewhereReturnPending' => $elsewhereReturnPending,
            'terminationNotice' => $terminationNotice,
        ]);
    }

    /**
     * PART 1 #4: "CGS Assign ... exclude[s] ineligible students" -- fetched
     * at a wider limit than the 20 actually returned, since eligibility is
     * checked in PHP (WorkstationEligibility has no equivalent SQL query of
     * its own) after the DB-level filters, not before.
     */
    public function searchStudents(Request $request, Workstation $workstation)
    {
        $q = trim((string) $request->query('q', ''));
        $gender = $workstation->location->gender;

        $assignedStudentIds = WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->pluck('student_id');

        $students = User::where('role', Role::STUDENT)
            ->whereNotIn('id', $assignedStudentIds)
            ->whereExists(function ($query) use ($gender) {
                $query->select('id')->from('student_genders')
                    ->whereColumn('student_genders.student_id', 'users.id')
                    ->where('student_genders.gender', $gender);
            })
            ->when($q !== '', fn ($query) => $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                    ->orWhere('matric_no', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->limit(100)
            // 'programme' is needed by WorkstationEligibility below but is
            // never sent to the browser -- stripped back off after filtering.
            ->get(['id', 'name', 'matric_no', 'email', 'programme'])
            ->filter(fn (User $user) => WorkstationEligibility::isEligible(StudentDirectory::fromUser($user)))
            ->take(20)
            ->map(fn (User $user) => $user->only(['id', 'name', 'matric_no', 'email']))
            ->values();

        return response()->json($students);
    }

    /**
     * PART 1's smart search suggestions. Scores every candidate (real +
     * demo students, seats, rooms) against the typed term with a simple
     * three-tier rule -- prefix, then contains, then a typo-tolerant
     * Levenshtein check per word -- rather than a separate SQL query per
     * tier; the whole catalogue (a few hundred students, 315 seats, 10
     * rooms) is small enough that scoring it in PHP on every request is
     * simpler and plenty fast. No filters applied: suggestions search
     * everything, same as the real search does once Search is pressed.
     */
    public function suggestions(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $candidates = [];

        foreach (StudentDirectory::all() as $identity) {
            $tier = $this->bestTier($term, [$identity->name, $identity->studentId, $identity->email]);

            if ($tier === null) {
                continue;
            }

            $candidates[] = [
                'type' => 'student',
                'text' => $identity->name,
                'label' => 'Student · '.$identity->studentId.' · '.$this->seatLabelFor($identity),
                'fill' => $identity->name,
                'highlight' => $this->highlightRange($term, $identity->name),
                'tier' => $tier,
            ];
        }

        foreach (Workstation::with('location')->get() as $seat) {
            $tier = $this->bestTier($term, [$seat->seat_code]);

            if ($tier === null) {
                continue;
            }

            $text = 'Seat '.$seat->seat_code;

            $candidates[] = [
                'type' => 'seat',
                'text' => $text,
                'label' => $seat->location->block.' · '.$seat->location->room_code,
                'fill' => $seat->seat_code,
                'highlight' => $this->highlightRange($term, $text),
                'tier' => $tier,
            ];
        }

        foreach (WorkstationLocation::all() as $room) {
            $tier = $this->bestTier($term, [$room->room_code]);

            if ($tier === null) {
                continue;
            }

            $candidates[] = [
                'type' => 'room',
                'text' => $room->room_code,
                'label' => $room->block.' · '.ucfirst($room->gender),
                'fill' => $room->room_code,
                'highlight' => $this->highlightRange($term, $room->room_code),
                'tier' => $tier,
            ];
        }

        $results = collect($candidates)
            ->sortBy('tier')
            ->take(6)
            ->map(fn (array $c) => array_diff_key($c, ['tier' => null]))
            ->values();

        return response()->json($results);
    }

    protected function seatLabelFor(StudentIdentity $identity): string
    {
        $request = WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->when($identity->user, fn ($q) => $q->where('student_id', $identity->user->id))
            ->when($identity->demoProfile, fn ($q) => $q->where('demo_profile_id', $identity->demoProfile->id))
            ->with('workstation')
            ->first();

        return $request ? 'Seat '.$request->workstation->seat_code : 'No seat';
    }

    /** @param array<int, ?string> $fields */
    protected function bestTier(string $term, array $fields): ?int
    {
        $best = null;

        foreach ($fields as $field) {
            if ($field === null || $field === '') {
                continue;
            }

            $tier = $this->tierFor($term, (string) $field);

            if ($tier !== null && ($best === null || $tier < $best)) {
                $best = $tier;
            }
        }

        return $best;
    }

    /** 0 = prefix, 1 = contains, 2 = fuzzy (typo-tolerant), null = no match at all. */
    protected function tierFor(string $term, string $field): ?int
    {
        $term = mb_strtolower($term);
        $field = mb_strtolower($field);

        if (str_starts_with($field, $term)) {
            return 0;
        }

        if (str_contains($field, $term)) {
            return 1;
        }

        foreach (preg_split('/\s+/', $field) as $word) {
            if ($word === '') {
                continue;
            }

            $tolerance = match (true) {
                strlen($word) >= 4 && strlen($word) <= 6 => 1,
                strlen($word) > 6 => 2,
                default => 0,
            };

            if ($tolerance > 0 && levenshtein($term, $word) <= $tolerance) {
                return 2;
            }
        }

        return null;
    }

    protected function highlightRange(string $term, string $text): ?array
    {
        $position = mb_stripos($text, $term);

        if ($position === false) {
            return null;
        }

        return ['start' => $position, 'length' => mb_strlen($term)];
    }

    public function reassign(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'new_workstation_id' => ['required', 'exists:workstations,id'],
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_REASSIGN)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $activeRequest = $workstation->activeRequest;

        if (! $activeRequest) {
            return redirect()->route('workstation.cgs.seat', $workstation)
                ->with('error', 'This seat has no known occupant to reassign.');
        }

        // PART 1 #4: Reassign excludes ineligible students -- giving an
        // already-ineligible occupant a different seat is still handing
        // them a seat.
        $occupant = $activeRequest->occupant();

        if ($occupant && ! WorkstationEligibility::isEligible($occupant)) {
            return redirect()->route('workstation.cgs.seat', $workstation)
                ->with('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        }

        $newSeat = Workstation::find($data['new_workstation_id']);

        try {
            $newRequest = $allocator->reassignSeat($activeRequest, $newSeat, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $newRequest->workstation_id)
            ->with('status', "Seat {$workstation->seat_code} has been reassigned to Seat {$newSeat->seat_code}.");
    }

    /**
     * PART 3 #4. The Reason dropdown has two groups: "Termination (5
     * working days notice)" starts the notice (seat stays Occupied, see
     * WorkstationAllocator::startTermination()); "Immediate correction"
     * releases right away, exactly as this action always did. Which group
     * the submitted reason belongs to decides which of the two allocator
     * calls happens -- not a separate route, so the seat-number-typing
     * confirmation and the optional Remark stay shared between them.
     */
    public function release(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_merge(
                WorkstationAllocator::REASONS_TERMINATION,
                WorkstationAllocator::REASONS_RELEASE_IMMEDIATE,
            ))],
            'remark' => ['nullable', 'string', 'max:1000'],
            'seat_confirmation' => ['required', 'string'],
        ]);

        if ($data['seat_confirmation'] !== $workstation->seat_code) {
            return redirect()->route('workstation.cgs.seat', $workstation)
                ->with('error', 'The seat number typed did not match Seat '.$workstation->seat_code.'. Nothing was changed.');
        }

        $activeRequest = $workstation->activeRequest;
        $isTermination = in_array($data['reason'], WorkstationAllocator::REASONS_TERMINATION, true);

        if ($isTermination) {
            if (! $activeRequest) {
                return redirect()->route('workstation.cgs.seat', $workstation)
                    ->with('error', 'This seat has no known occupant to notify.');
            }

            try {
                $notice = $allocator->startTermination($activeRequest, WorkstationHistory::ACTOR_CGS, $request->user(), $data['reason'], $data['remark'] ?? null);
            } catch (RuntimeException $e) {
                return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
            }

            return redirect()->route('workstation.cgs.seat', $workstation)
                ->with('status', "Seat {$workstation->seat_code}: termination notice issued. The student must vacate by {$notice->vacate_by_date->format('j M Y')}.");
        }

        try {
            if ($activeRequest) {
                $allocator->releaseSeat($activeRequest, WorkstationHistory::ACTOR_CGS, $request->user(), $data['reason'], $data['remark'] ?? null);
            } elseif ($workstation->status === Workstation::STATUS_OCCUPIED) {
                $allocator->forceReleaseSeat($workstation, $request->user(), $data['reason'], $data['remark'] ?? null);
            } else {
                return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'This seat is not currently occupied.');
            }
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} has been released.");
    }

    /** PART 3 #4: a seat already mid-notice -- releases right away instead of waiting for the vacate-by day. */
    public function releaseNow(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_RELEASE_NOW)],
            'remark' => ['nullable', 'string', 'max:1000'],
            'seat_confirmation' => ['required', 'string'],
        ]);

        if ($data['seat_confirmation'] !== $workstation->seat_code) {
            return redirect()->route('workstation.cgs.seat', $workstation)
                ->with('error', 'The seat number typed did not match Seat '.$workstation->seat_code.'. Nothing was changed.');
        }

        $activeRequest = $workstation->activeRequest;

        if (! $activeRequest) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'This seat has no known occupant to release.');
        }

        try {
            $allocator->releaseSeat($activeRequest, WorkstationHistory::ACTOR_CGS, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} has been released.");
    }

    /** PART 3 #4: cancels an active notice -- the seat was never actually vacated. */
    public function withdrawTermination(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_WITHDRAW_TERMINATION)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $notice = $workstation->activeRequest?->activeTerminationNotice;

        if (! $notice) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'This seat has no active termination notice.');
        }

        try {
            $allocator->withdrawTermination($notice, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "The termination notice for Seat {$workstation->seat_code} has been withdrawn.");
    }

    public function setMaintenance(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_MAINTENANCE)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $allocator->setMaintenance($workstation, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} set to Under Maintenance.");
    }

    public function releaseHold(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_RELEASE_HOLD)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $allocator->releaseHold($workstation, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} released; the locker key is marked Lost.");
    }

    public function restore(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:1000']]);

        try {
            $allocator->restoreSeat($workstation, $request->user(), $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} restored to Available.");
    }

    public function assign(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_ASSIGN)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::where('id', $data['student_id'])->where('role', Role::STUDENT)->first();

        if (! $student) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'Select a valid student.');
        }

        // The search endpoint already excludes ineligible students from the
        // list CGS picks from, but that is a display-time filter, not a
        // guarantee -- a direct POST (a stale page, a replayed request, a
        // crafted one) must still be rejected here, server-side.
        if (! WorkstationEligibility::isEligible(StudentDirectory::fromUser($student))) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        }

        try {
            $allocator->assignStudent($student, $workstation, $request->user(), $data['reason'], $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', "Seat {$workstation->seat_code} assigned to {$student->name}.");
    }

    public function markKeyCollected(Request $request, Workstation $workstation)
    {
        $activeRequest = $workstation->activeRequest;

        if (! $activeRequest) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'No student is assigned to this seat.');
        }

        // On the request directly, not by re-deriving via a student_id --
        // works the same for a demo profile's key, which has none.
        $lockerKey = LockerKey::where('workstation_request_id', $activeRequest->id)
            ->where('status', LockerKey::STATUS_REQUESTED)
            ->latest('requested_at')
            ->first();

        if (! $lockerKey) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'This locker key is not awaiting collection.');
        }

        try {
            $lockerKey->collect(WorkstationHistory::ACTOR_CGS, $request->user()->name);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', 'Locker key marked as collected.');
    }

    public function markKeyReturned(Request $request, Workstation $workstation)
    {
        $key = $workstation->lockerKeysHistory()
            ->where('locker_keys.status', LockerKey::STATUS_RETURN_PENDING)
            ->latest('requested_at')
            ->first();

        if (! $key) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', 'No locker key is awaiting return for this seat.');
        }

        try {
            $key->markReturned(WorkstationHistory::ACTOR_CGS, $request->user()->name);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.seat', $workstation)->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.seat', $workstation)->with('status', 'Locker key marked as returned.');
    }

    public function exportSeats(Request $request)
    {
        $filters = $this->filtersFrom($request);
        $rows = $this->seatRows($filters);
        $stamp = now('Asia/Kuala_Lumpur')->format('Y-m-d');

        return Excel::download(new WorkstationSeatsExport($rows), "workstation_seats_{$stamp}.xlsx");
    }

    public function exportHistory()
    {
        $stamp = now('Asia/Kuala_Lumpur')->format('Y-m-d');

        return Excel::download(new WorkstationHistoryExport, "workstation_history_{$stamp}.xlsx");
    }

    protected function filtersFrom(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'block' => (string) $request->query('block', ''),
            'location_id' => (string) $request->query('location_id', ''),
            'seat_status' => (string) $request->query('seat_status', ''),
            'gender' => (string) $request->query('gender', ''),
            'key_status' => (string) $request->query('key_status', ''),
        ];
    }

    protected function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /** Delegates to WorkstationStatistics::overall() -- the assistant's CGS lookups read the exact same numbers, see that class's own doc. */
    protected function stats(): array
    {
        return WorkstationStatistics::overall();
    }

    /**
     * Every seat matching the given filters, across all rooms, in numeric
     * order within each room -- the single source of truth both the results
     * grid (paginated) and the Export Results download (not paginated) read
     * from, so the two can never disagree about which seats matched.
     */
    protected function seatRows(array $filters): Collection
    {
        $query = Workstation::query()->with([
            'location',
            'activeRequest.student',
            'activeRequest.demoProfile',
            'activeRequest.lockerKey',
            'activeRequest.activeTerminationNotice',
            'lockerKeysHistory.student',
            'lockerKeysHistory.demoProfile',
        ]);

        if ($filters['block'] !== '') {
            $query->whereHas('location', fn ($q) => $q->where('block', $filters['block']));
        }
        if ($filters['location_id'] !== '') {
            $query->where('workstation_location_id', $filters['location_id']);
        }
        if ($filters['seat_status'] === self::SEAT_STATUS_TERMINATION_NOTICE) {
            // PART 3 #4: Occupied seats with an active notice -- not a
            // Workstation.status value of its own, the seat really is
            // still Occupied throughout.
            $query->where('status', Workstation::STATUS_OCCUPIED)
                ->whereHas('activeRequest.activeTerminationNotice');
        } elseif ($filters['seat_status'] !== '') {
            $query->where('status', $filters['seat_status']);
        }
        if ($filters['gender'] !== '') {
            $query->whereHas('location', fn ($q) => $q->where('gender', $filters['gender']));
        }

        return SeatOrder::queryAcrossRooms($query)->get()
            ->map(fn ($seat) => $this->presentSeat($seat))
            ->filter(function (array $row) use ($filters) {
                if ($filters['key_status'] !== '' && $row['key_filter_value'] !== $filters['key_status']) {
                    return false;
                }

                if ($filters['q'] !== '') {
                    $needle = mb_strtolower($filters['q']);
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $row['student_name'], $row['student_matric'], $row['student_email'],
                        $row['seat_code'], $row['room_code'],
                        // PART 3 #4: "make the search find them" -- typing
                        // "termination" or "notice" or the reason itself
                        // finds a seat mid-notice.
                        $row['termination_notice'] ? 'Termination notice '.$row['termination_notice']->reason : null,
                    ])));

                    if (! str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                return true;
            })
            ->pipe(fn ($rows) => SeatOrder::sortAcrossRooms($rows, fn (array $row) => [$row['block'], $row['room_code'], $row['seat_code'], $row['seat']->id ?? 0]));
    }

    /** @return array<string, mixed> */
    protected function presentSeat(Workstation $seat): array
    {
        $activeRequest = $seat->activeRequest;
        $occupant = $activeRequest?->occupant();
        $currentKey = $activeRequest?->lockerKey;

        $outstandingKey = $seat->lockerKeysHistory
            ->filter(fn ($key) => $key->status === LockerKey::STATUS_RETURN_PENDING)
            ->sortByDesc('requested_at')
            ->first();

        $keyForDisplay = $currentKey ?: (! $occupant ? $outstandingKey : null);
        $formerOccupant = (! $occupant && $outstandingKey) ? $outstandingKey->occupant() : null;

        $keyOverdue = $keyForDisplay
            && $keyForDisplay->status === LockerKey::STATUS_RETURN_PENDING
            && $keyForDisplay->isOverdueForReturn();

        $keyFilterValue = $keyOverdue ? 'overdue' : ($keyForDisplay ? $keyForDisplay->status : 'not_requested');

        $keyDates = match (true) {
            $keyForDisplay === null => null,
            $keyForDisplay->status === LockerKey::STATUS_REQUESTED => 'Requested '.$keyForDisplay->requested_at->format('j M Y').', collect by '.$keyForDisplay->collectByDate()->format('j M Y'),
            $keyForDisplay->status === LockerKey::STATUS_COLLECTED => 'Collected '.$keyForDisplay->collected_at->format('j M Y'),
            $keyForDisplay->status === LockerKey::STATUS_RETURN_PENDING => 'Return due '.$keyForDisplay->return_due_at->format('j M Y').($keyOverdue ? ' (overdue)' : ''),
            default => null,
        };

        return [
            'seat' => $seat,
            'block' => $seat->location->block,
            'room_code' => $seat->location->room_code,
            'room_name' => $seat->location->name,
            'room_gender' => $seat->location->gender,
            'seat_code' => $seat->seat_code,
            'status' => $seat->status,
            'occupant' => $occupant,
            'has_login' => $occupant?->hasLogin,
            'student_name' => $occupant?->name ?? $formerOccupant?->name,
            'student_matric' => $occupant?->studentId ?? $formerOccupant?->studentId,
            'student_email' => $occupant?->email ?? $formerOccupant?->email,
            'student_programme' => $occupant?->programme,
            'is_former_student' => ! $occupant && $formerOccupant !== null,
            'key_status_value' => $keyForDisplay?->status,
            'key_status_label' => $keyForDisplay ? self::KEY_LABELS[$keyForDisplay->status] ?? null : 'Not Requested',
            'key_overdue' => $keyOverdue,
            'key_filter_value' => $keyFilterValue,
            'key_dates' => $keyDates,
            'allocated_at' => $activeRequest?->requested_at,
            // PART 3 #4: the badge and the Seat Status filter both read
            // this -- null when there is none, never a Workstation status
            // value of its own.
            'termination_notice' => $activeRequest?->activeTerminationNotice,
        ];
    }
}
