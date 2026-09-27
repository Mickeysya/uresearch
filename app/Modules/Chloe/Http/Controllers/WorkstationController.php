<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Home (choose block) -> Room (choose room, filtered by the student's own
 * gender) -> Seat map, the same hierarchy as CGS's own seat map reference.
 *
 * `users` carries no gender column, and Hard Rule 2 forbids adding one
 * there, so a student's gender for room-eligibility purposes lives in this
 * module's own `student_genders` table instead — set by the student
 * themselves on first visit, correctable by CGS. See genderFor().
 */
class WorkstationController extends Controller
{
    public function index(Request $request)
    {
        $activeRequest = $this->activeRequestFor($request);
        $gender = $this->genderFor($request);

        $blocks = WorkstationLocation::query()
            ->select('block')
            ->distinct()
            ->orderBy('block')
            ->pluck('block');

        return view('chloe::workstation.select', compact('activeRequest', 'blocks', 'gender'));
    }

    public function setGender(Request $request)
    {
        $data = $request->validate([
            'gender' => ['required', Rule::in([WorkstationLocation::GENDER_MALE, WorkstationLocation::GENDER_FEMALE])],
        ]);

        StudentGender::updateOrCreate(['student_id' => $request->user()->id], $data);

        return redirect()->route('workstation.select')->with('status', 'Saved.');
    }

    public function rooms(Request $request, string $block)
    {
        $activeRequest = $this->activeRequestFor($request);
        $gender = $this->genderFor($request);

        if (! $gender) {
            return redirect()->route('workstation.select')
                ->with('error', 'Set your gender first — rooms are shown by gender designation.');
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

    public function seats(Request $request, WorkstationLocation $workstationLocation)
    {
        $activeRequest = $this->activeRequestFor($request);
        $gender = $this->genderFor($request);

        if (! $gender) {
            return redirect()->route('workstation.select')
                ->with('error', 'Set your gender first — rooms are shown by gender designation.');
        }

        // Belt-and-suspenders against a direct URL to a room of the other
        // designation — the room list above already filters, but a link or a
        // stale bookmark could still point here.
        abort_if($workstationLocation->gender !== $gender, 403);

        // Ordered by `position`, then grouped — the collection is already in
        // the right order, so each cluster's seats land together and the
        // clusters themselves come out in the room's own reading order. See
        // the workstations migration for why this can't be derived by
        // sorting seat_code.
        $seatsByCluster = $workstationLocation->workstations()
            ->orderBy('position')
            ->get()
            ->groupBy(fn ($seat) => $seat->cluster ?? 'Seats');

        return view('chloe::workstation.seats', compact('workstationLocation', 'seatsByCluster', 'activeRequest'));
    }

    public function store(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $alreadyHolding = WorkstationRequest::where('student_id', $request->user()->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->exists();

        if ($alreadyHolding) {
            return redirect()->route('workstation.seats', $workstation->workstation_location_id)
                ->with('error', 'You already have a workstation. Release it before selecting another.');
        }

        $result = $allocator->request($request->user(), $workstation);

        if (! $result['allocated']) {
            return redirect()->route('workstation.seats', $workstation->workstation_location_id)
                ->with('error', "Seat {$workstation->seat_code} was just taken by another student. Please pick another seat.");
        }

        return redirect()->route('workstation.seats', $workstation->workstation_location_id)
            ->with('status', "Seat {$workstation->seat_code} confirmed. A confirmation email is on its way.");
    }

    public function release(Request $request, WorkstationRequest $workstationRequest, WorkstationAllocator $allocator)
    {
        if ($workstationRequest->student_id !== $request->user()->id || ! $workstationRequest->isActive()) {
            abort(403);
        }

        $locationId = $workstationRequest->workstation->workstation_location_id;

        $allocator->release($workstationRequest);

        return redirect()->route('workstation.seats', $locationId)
            ->with('status', 'Workstation released.');
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
}
