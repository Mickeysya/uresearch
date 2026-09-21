<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\WorkstationRequest;
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
                ->with('error', 'You need an active workstation before requesting a locker key.');
        }

        $openRequest = LockerKey::where('workstation_request_id', $activeRequest->id)
            ->whereIn('status', [LockerKey::STATUS_REQUESTED, LockerKey::STATUS_COLLECTED])
            ->exists();

        if ($openRequest) {
            return redirect()->route('workstation.select')
                ->with('error', 'You already have a locker key request in progress.');
        }

        LockerKey::create([
            'workstation_request_id' => $activeRequest->id,
            'student_id' => $request->user()->id,
            'status' => LockerKey::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        return redirect()->route('workstation.select')
            ->with('status', 'Locker key requested. Collect it from the CGS office.');
    }
}
