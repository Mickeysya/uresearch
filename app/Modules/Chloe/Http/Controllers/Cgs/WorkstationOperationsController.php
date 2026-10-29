<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * What is left of the pre-rewrite CGS screen now that
 * WorkstationManagementController owns the landing page, the room/seat
 * drill-down and every per-seat action. setStudentGender() was removed
 * entirely, along with its route and the "Student Genders" section on the
 * landing page -- gender is a student record, not something any
 * Workstation screen edits. student_genders is the only gender column
 * anywhere in this database (see the module README); it is now read-only
 * from every Workstation page, set only by DatabaseSeeder's demo data. The
 * four action methods below (forceAssign/forceRelease/
 * markLockerCollected/markLockerReturned) are no longer linked from any
 * view — the new seat-details page's Assign/Release/Mark-as-Collected/
 * Mark-as-Returned actions call the real STEP 2 service methods directly —
 * but their routes are kept working and now delegate to those same
 * methods, so a request to one of these old routes still writes a History
 * row, still requires a reason from the fixed list, and still follows the
 * same rules as the new screen. See the module README / PR description for
 * the full old-action-by-old-action account.
 */
class WorkstationOperationsController extends Controller
{
    /**
     * Delegates to assignStudent() -- the student still must have no seat
     * and must match the room's gender, the reason is now validated against
     * the fixed REASONS_ASSIGN list instead of accepted as free text, and a
     * History row (and the Assigned email) is now written, none of which
     * the old forceAssign() on the allocator did on its own.
     */
    public function forceAssign(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'reason' => ['required', Rule::in(WorkstationAllocator::REASONS_ASSIGN)],
        ]);

        $student = User::where('id', $data['student_id'])->where('role', Role::STUDENT)->first();

        if (! $student) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'Select a valid student.');
        }

        try {
            $allocator->assignStudent($student, $workstation, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.operations')->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.operations')
            ->with('status', "Seat {$workstation->seat_code} assigned to {$student->name}.");
    }

    /**
     * Delegates to releaseSeat() when the seat has a tracked
     * WorkstationRequest (the normal case) -- reason now validated against
     * REASONS_RELEASE_IMMEDIATE (PART 3 retired REASONS_RELEASE_CGS; this
     * old route has no UI for choosing the 5-working-day notice path, so it
     * stays on the immediate-release group, same as it always released
     * right away). A History row is written, the student emailed per STEP
     * 6. Falls back to forceReleaseSeat() only for a seat seeded as
     * occupied with no request behind it at all, exactly as
     * WorkstationManagementController::release() does.
     */
    public function forceRelease(Request $request, Workstation $workstation, WorkstationAllocator $allocator)
    {
        $data = $request->validate(['reason' => ['required', Rule::in(WorkstationAllocator::REASONS_RELEASE_IMMEDIATE)]]);

        if ($workstation->status !== Workstation::STATUS_OCCUPIED) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'That workstation is not currently occupied.');
        }

        $activeRequest = $workstation->activeRequest;

        try {
            if ($activeRequest) {
                $allocator->releaseSeat($activeRequest, WorkstationHistory::ACTOR_CGS, $request->user(), $data['reason']);
            } else {
                $allocator->forceReleaseSeat($workstation, $request->user(), $data['reason']);
            }
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.operations')->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.operations')->with('status', "Seat {$workstation->seat_code} released.");
    }

    /**
     * Delegates to LockerKey::markAsCollected(), which this predates — same
     * end state, now also writing a History row and the Collected email
     * that a raw ->update() here never did.
     */
    public function markLockerCollected(Request $request, LockerKey $lockerKey)
    {
        if ($lockerKey->status !== LockerKey::STATUS_REQUESTED || ! $lockerKey->student) {
            return redirect()->route('workstation.cgs.operations')->with('error', 'That locker key is not awaiting collection.');
        }

        try {
            LockerKey::markAsCollected($lockerKey->student, WorkstationHistory::ACTOR_CGS, $request->user()->name);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.operations')->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.operations')->with('status', 'Locker key marked as collected.');
    }

    /**
     * This route predates Return Pending entirely -- it used to flip
     * Collected straight to Returned while the seat was still occupied,
     * which the current model has no place for (a key only becomes
     * returnable once its seat is released). Now it only acts when the key
     * is genuinely awaiting return; otherwise it says so rather than
     * silently doing the no-longer-valid old thing.
     */
    public function markLockerReturned(Request $request, LockerKey $lockerKey)
    {
        if ($lockerKey->status !== LockerKey::STATUS_RETURN_PENDING) {
            return redirect()->route('workstation.cgs.operations')
                ->with('error', 'That locker key is not awaiting return. A key only becomes returnable once its seat has been released.');
        }

        try {
            $lockerKey->markReturned(WorkstationHistory::ACTOR_CGS, $request->user()->name);
        } catch (RuntimeException $e) {
            return redirect()->route('workstation.cgs.operations')->with('error', $e->getMessage());
        }

        return redirect()->route('workstation.cgs.operations')->with('status', 'Locker key marked as returned.');
    }
}
