<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Services\WorkstationSeatCatalogue;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Admin-managed seat catalogue — the digital replacement for the PowerPoint
 * layout deck. CGS only; students never see this screen.
 *
 * Seats are identified by number only. Numbers come from the persistent counter (WorkstationSeatCatalogue), never from
 * the form, so they are never reused. The old "cluster" grouping is no longer shown or set here; the column stays so
 * existing rows keep working.
 */
class WorkstationCatalogController extends Controller
{
    public function index(WorkstationSeatCatalogue $catalogue)
    {
        $locations = WorkstationLocation::withCount('workstations')
            ->with(['workstations' => fn ($q) => $q->orderByRaw('CAST(seat_code AS UNSIGNED) ASC')])
            ->orderBy('block')
            ->orderBy('room_code')
            ->get();

        $nextSeatNumber = $catalogue->nextNumber();

        return view('chloe::workstation.cgs.catalog', compact('locations', 'nextSeatNumber'));
    }

    /** Add Location: Block, Room and n creates the room and its n seats in one transaction. */
    public function storeLocation(Request $request, WorkstationSeatCatalogue $catalogue)
    {
        $data = $request->validate([
            'block' => ['required', 'string', 'max:10'],
            'room_code' => ['required', 'string', 'max:30', 'unique:workstation_locations,room_code'],
            'gender' => ['required', Rule::in([WorkstationLocation::GENDER_MALE, WorkstationLocation::GENDER_FEMALE])],
            'name' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $range = DB::transaction(function () use ($data, $catalogue) {
            $location = WorkstationLocation::create([
                'block' => $data['block'],
                'room_code' => $data['room_code'],
                'gender' => $data['gender'],
                'name' => ($data['name'] ?? null) ?: $data['room_code'],
                'description' => $data['description'] ?? null,
            ]);

            return $catalogue->addSeats($location, (int) $data['quantity']);
        });

        return redirect()->route('workstation.cgs.catalog')->with('status', $this->addedMessage($range));
    }

    /** Add n more seats to an existing room. */
    public function storeSeat(Request $request, WorkstationSeatCatalogue $catalogue)
    {
        $data = $request->validate([
            'workstation_location_id' => ['required', 'exists:workstation_locations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $range = $catalogue->addSeats(WorkstationLocation::findOrFail($data['workstation_location_id']), (int) $data['quantity']);

        return redirect()->route('workstation.cgs.catalog')->with('status', $this->addedMessage($range));
    }

    public function updateSeat(Request $request, Workstation $workstation)
    {
        if ($workstation->status === Workstation::STATUS_OCCUPIED) {
            return redirect()->route('workstation.cgs.catalog')
                ->with('error', 'This seat is occupied — force-release it from the operations screen before editing.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([
                Workstation::STATUS_AVAILABLE, Workstation::STATUS_RESERVED, Workstation::STATUS_DISABLED,
            ])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $workstation->update($data);

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Seat updated.');
    }

    public function destroySeat(Request $request, Workstation $workstation, WorkstationSeatCatalogue $catalogue)
    {
        $label = 'Seat '.$workstation->seat_code;

        if ($message = $catalogue->retire($workstation, $request->user())) {
            return redirect()->route('workstation.cgs.catalog')->with('error', $message);
        }

        return redirect()->route('workstation.cgs.catalog')->with('status', $label.' deleted.');
    }

    /** Upload or replace the room layout image. */
    public function storeLayout(Request $request, WorkstationLocation $workstationLocation)
    {
        $request->validate([
            'layout' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:5120'],
        ], [
            'layout.max' => 'The layout image must be 5 MB or smaller.',
            'layout.mimes' => 'The layout image must be a PNG, JPG or WebP file.',
            'layout.mimetypes' => 'The layout image must be a PNG, JPG or WebP file.',
            'layout.image' => 'The layout image must be a PNG, JPG or WebP file.',
        ]);

        $file = $request->file('layout');
        $mime = $file->getMimeType();
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? 'png';
        $path = 'workstation/layouts/room-'.$workstationLocation->id.'-'.bin2hex(random_bytes(8)).'.'.$extension;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $old = $workstationLocation->layout_image_path;
        $workstationLocation->update(['layout_image_path' => $path, 'layout_image_mime' => $mime]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Layout image saved for '.$workstationLocation->name.'.');
    }

    public function destroyLayout(WorkstationLocation $workstationLocation)
    {
        if ($workstationLocation->layout_image_path) {
            Storage::disk('local')->delete($workstationLocation->layout_image_path);
            $workstationLocation->update(['layout_image_path' => null, 'layout_image_mime' => null]);
        }

        return redirect()->route('workstation.cgs.catalog')->with('status', 'Layout image removed from '.$workstationLocation->name.'.');
    }

    public function layout(WorkstationLocation $workstationLocation)
    {
        return $workstationLocation->layoutResponse();
    }

    /** @param array{first: int, last: int, count: int} $range */
    protected function addedMessage(array $range): string
    {
        return $range['count'] === 1
            ? "Added 1 seat: Seat {$range['first']}."
            : "Added {$range['count']} seats: Seat {$range['first']} to Seat {$range['last']}.";
    }
}
