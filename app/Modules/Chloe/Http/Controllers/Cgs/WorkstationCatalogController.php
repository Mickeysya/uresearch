<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-managed seat catalogue — the digital replacement for the PowerPoint
 * layout deck. CGS only; students never see this screen.
 */
class WorkstationCatalogController extends Controller
{
    public function index()
    {
        $locations = WorkstationLocation::withCount('workstations')
            ->with(['workstations' => fn ($q) => $q->orderBy('position')])
            ->orderBy('block')
            ->orderBy('room_code')
            ->get();

        return view('chloe::workstation.cgs.catalog', compact('locations'));
    }

    public function storeLocation(Request $request)
    {
        $data = $request->validate([
            'block' => ['required', 'string', 'max:10'],
            'room_code' => ['required', 'string', 'max:30', 'unique:workstation_locations,room_code'],
            'gender' => ['required', Rule::in([WorkstationLocation::GENDER_MALE, WorkstationLocation::GENDER_FEMALE])],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        WorkstationLocation::create($data);

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Location added.');
    }

    public function storeSeat(Request $request)
    {
        $data = $request->validate([
            'workstation_location_id' => ['required', 'exists:workstation_locations,id'],
            'seat_code' => [
                'required', 'string', 'max:20',
                Rule::unique('workstations')->where('workstation_location_id', $request->input('workstation_location_id')),
            ],
            'cluster' => ['nullable', 'string', 'max:60'],
            'position' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        Workstation::create($data + ['status' => Workstation::STATUS_AVAILABLE]);

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Seat added.');
    }

    public function updateSeat(Request $request, Workstation $workstation)
    {
        if ($workstation->status === Workstation::STATUS_OCCUPIED) {
            return redirect()->route('workstation.cgs.catalog')
                ->with('error', 'This seat is occupied — force-release it from the operations screen before editing.');
        }

        $data = $request->validate([
            'seat_code' => [
                'required', 'string', 'max:20',
                Rule::unique('workstations')->where('workstation_location_id', $workstation->workstation_location_id)->ignore($workstation->id),
            ],
            'status' => ['required', Rule::in([
                Workstation::STATUS_AVAILABLE, Workstation::STATUS_RESERVED, Workstation::STATUS_DISABLED,
            ])],
            'cluster' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $workstation->update($data);

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Seat updated.');
    }

    public function destroySeat(Workstation $workstation)
    {
        if ($workstation->requests()->exists()) {
            return redirect()->route('workstation.cgs.catalog')
                ->with('error', 'This seat has request history — disable it instead of deleting it.');
        }

        $workstation->delete();

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Seat deleted.');
    }
}
