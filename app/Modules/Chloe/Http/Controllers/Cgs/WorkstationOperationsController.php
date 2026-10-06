<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The operational screen this module's three dashboard widgets live on
 * (Available / Occupied / Pending Locker Key Collection), plus CGS's manual
 * override path. Kept self-contained inside this module rather than injected
 * into Core's CgsDashboard, which has no per-module widget hook today — see
 * the module README for why.
 */
class WorkstationOperationsController extends Controller
{
    public function index()
    {
        // Rooms, each carrying its own seat list (for the override section)
        // and available/occupied counts (for the per-room breakdown) — one
        // query rather than the 315-row flat seat table this used to be
        // before the real seat map reference replaced the placeholder demo
        // catalogue. See the module README.
        $rooms = WorkstationLocation::withCount([
            'workstations',
            'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
        ])
            ->with(['workstations' => fn ($q) => $q->orderBy('position'), 'workstations.activeRequest.student', 'workstations.activeRequest.lockerKey'])
            ->orderBy('block')
            ->orderBy('room_code')
            ->get();

        $availableCount = $rooms->sum('available_count');

        // Not $occupiedRequests->count(): the catalogue was seeded straight
        // from CGS's existing seat map, and most seats it already showed
        // taken have no app-tracked request behind them. Workstation.status
        // is the source of truth for occupancy; see activeRequest() on that
        // model for how the per-room detail below shows "who" when known.
        $occupiedCount = Workstation::where('status', Workstation::STATUS_OCCUPIED)->count();

        $pendingLockerKeys = LockerKey::with('student', 'workstationRequest.workstation.location')
            ->where('status', LockerKey::STATUS_REQUESTED)
            ->oldest('requested_at')
            ->get();

        $students = User::where('role', Role::STUDENT)
            ->orderBy('name')
            ->get()
            ->each(fn ($student) => $student->gender = StudentGender::where('student_id', $student->id)->value('gender'));

        return view('chloe::workstation.cgs.operations', compact(
            'rooms', 'availableCount', 'occupiedCount', 'pendingLockerKeys', 'students'
        ));
    }

    public function setStudentGender(Request $request, User $user)
    {
        $data = $request->validate([
            'gender' => ['required', Rule::in([WorkstationLocation::GENDER_MALE, WorkstationLocation::GENDER_FEMALE])],
        ]);

        abort_if($user->role !== Role::STUDENT, 404);

        StudentGender::updateOrCreate(['student_id' => $user->id], $data);

        return redirect()->route('workstation.cgs.operations')->with('status', "Gender set for {$user->name}.");
    }

    public function forceAssign(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $student = User::where('id', $data['student_id'])->where('role', Role::STUDENT)->first();

        if (! $student) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'Select a valid student.');
        }

        try {
            $allocator->forceAssign($student, $workstation, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.operations')->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.operations')
            ->with('status', "Seat {$workstation->seat_code} force-assigned to {$student->name}.");
    }

    public function forceRelease(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        if ($workstation->status !== Workstation::STATUS_OCCUPIED) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'That workstation is not currently occupied.');
        }

        $allocator->forceReleaseSeat($workstation, $request->user(), $data['reason']);

        return redirect()->route('workstation.cgs.operations')->with('status', "Seat {$workstation->seat_code} force-released.");
    }

    public function markLockerCollected(LockerKey $lockerKey)
    {
        if ($lockerKey->status !== LockerKey::STATUS_REQUESTED) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'That locker key is not awaiting collection.');
        }

        $lockerKey->update(['status' => LockerKey::STATUS_COLLECTED, 'collected_at' => now()]);

        return redirect()->route('workstation.cgs.operations')->with('status', 'Locker key marked as collected.');
    }

    public function markLockerReturned(LockerKey $lockerKey)
    {
        if ($lockerKey->status === LockerKey::STATUS_RETURNED) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'That locker key is already marked returned.');
        }

        $lockerKey->update(['status' => LockerKey::STATUS_RETURNED, 'returned_at' => now()]);

        return redirect()->route('workstation.cgs.operations')->with('status', 'Locker key marked as returned.');
    }
}
