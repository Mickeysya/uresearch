<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LockerKeyController extends Controller
{
    public function store(Request $request)
    {
        $activeRequest = WorkstationRequest::where('student_id', $request->user()->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if (! $activeRequest) {
            return redirect()->route('workstation.select')
                ->with('error', 'Select a workstation first to request your locker key.');
        }

        // PART 4: any earlier seat's key, not just this one's -- a student
        // who still owes CGS a key cannot be handed a new one just because
        // they moved to a different seat in the meantime.
        $hasUnreturnedKey = LockerKey::where('student_id', $request->user()->id)
            ->where('status', LockerKey::STATUS_RETURN_PENDING)
            ->exists();

        if ($hasUnreturnedKey) {
            return redirect()->route('workstation.select')
                ->with('error', 'Please return your previous locker key first.');
        }

        // The seat's own last key, not just this WorkstationRequest's --
        // a previous occupant's key can still be Return Pending on the
        // same physical seat after a reassign/release put a new student
        // on it.
        $seatLastKey = LockerKey::whereHas('workstationRequest', fn ($q) => $q->where('workstation_id', $activeRequest->workstation_id))
            ->latest('requested_at')
            ->first();

        if ($seatLastKey?->status === LockerKey::STATUS_RETURN_PENDING) {
            return redirect()->route('workstation.select')
                ->with('error', 'The locker key for this seat has not been returned yet. Please contact CGS.');
        }

        // Only REQUESTED/COLLECTED block a new one -- a CANCELLED row (the
        // student's own cancel, or the 7-day auto-cancel) is history, not an
        // open request, so it does not stop them requesting again.
        $openRequest = LockerKey::where('workstation_request_id', $activeRequest->id)
            ->whereIn('status', [LockerKey::STATUS_REQUESTED, LockerKey::STATUS_COLLECTED])
            ->exists();

        if ($openRequest) {
            return redirect()->route('workstation.select')
                ->with('error', 'You already have a locker key request in progress.');
        }

        $lockerKey = LockerKey::create([
            'workstation_request_id' => $activeRequest->id,
            'student_id' => $request->user()->id,
            'status' => LockerKey::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        $request->user()->notify(new LockerKeyNotice($lockerKey, LockerKeyNotice::REQUESTED));

        WorkstationHistory::record(
            seat: $activeRequest->workstation,
            actorType: WorkstationHistory::ACTOR_STUDENT,
            actorName: $request->user()->name,
            action: WorkstationHistory::ACTION_KEY_REQUESTED,
            beforeState: null,
            afterState: LockerKey::STATUS_REQUESTED,
            student: $request->user(),
        );

        return redirect()->route('workstation.select')
            ->with('status', 'Locker key requested. Collect it from the CGS office.');
    }

    public function cancel(Request $request)
    {
        $activeRequest = WorkstationRequest::where('student_id', $request->user()->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        $lockerKey = $activeRequest
            ? LockerKey::where('workstation_request_id', $activeRequest->id)
                ->where('student_id', $request->user()->id)
                ->where('status', LockerKey::STATUS_REQUESTED)
                ->latest('requested_at')
                ->first()
            : null;

        // Collected is terminal (rule 6) -- that row's status is never
        // REQUESTED any more, so this same lookup already refuses it; no
        // separate check needed.
        if (! $lockerKey) {
            return redirect()->route('workstation.select')
                ->with('error', 'You have no locker key request to cancel.');
        }

        $lockerKey->update(['status' => LockerKey::STATUS_CANCELLED]);

        WorkstationHistory::record(
            seat: $activeRequest->workstation,
            actorType: WorkstationHistory::ACTOR_STUDENT,
            actorName: $request->user()->name,
            action: WorkstationHistory::ACTION_KEY_CANCELLED,
            beforeState: LockerKey::STATUS_REQUESTED,
            afterState: LockerKey::STATUS_CANCELLED,
            student: $request->user(),
        );

        return redirect()->route('workstation.select')
            ->with('status', 'Locker key request cancelled.');
    }
}
