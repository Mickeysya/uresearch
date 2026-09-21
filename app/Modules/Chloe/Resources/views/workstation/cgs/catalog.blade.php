@extends('core::layouts.app')

@section('title', 'Workstation Catalogue')

@section('content')
<h2 style="color: var(--navy);"><a href="{{ route('workstation.cgs.operations') }}" style="color:inherit;">Workstation Management</a> / Catalogue</h2>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Add Location</h2>
        <div class="card-divider"></div>
        <form method="POST" action="{{ route('workstation.cgs.catalog.locations.store') }}">
            @csrf
            <label for="block">Block</label>
            <input type="text" name="block" id="block" required maxlength="10" placeholder="e.g. J2" value="{{ old('block') }}">
            @error('block') <p class="field-error">{{ $message }}</p> @enderror

            <label for="room_code">Room Code</label>
            <input type="text" name="room_code" id="room_code" required maxlength="30" placeholder="e.g. N2-03-01-02" value="{{ old('room_code') }}">
            @error('room_code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="gender">Gender</label>
            <select name="gender" id="gender" required>
                <option value="">— Select —</option>
                <option value="male" @selected(old('gender') === 'male')>Male</option>
                <option value="female" @selected(old('gender') === 'female')>Female</option>
            </select>
            @error('gender') <p class="field-error">{{ $message }}</p> @enderror

            <label for="name">Room Label</label>
            <input type="text" name="name" id="name" required maxlength="150" placeholder="e.g. PG LAB MALE 1.9" value="{{ old('name') }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="description">Description <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="description" id="description" maxlength="255" value="{{ old('description') }}">

            <button type="submit">Add Location</button>
        </form>
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Add Seat</h2>
        <div class="card-divider"></div>
        <form method="POST" action="{{ route('workstation.cgs.catalog.seats.store') }}">
            @csrf
            <label for="workstation_location_id">Location</label>
            <select name="workstation_location_id" id="workstation_location_id" required>
                <option value="">— Select —</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>

            <label for="seat_code">Seat Number</label>
            <input type="text" name="seat_code" id="seat_code" required maxlength="20" placeholder="e.g. 17">
            @error('seat_code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="cluster">Cluster <span style="color: var(--text-grey)">(optional — which desk group this seat belongs to)</span></label>
            <input type="text" name="cluster" id="cluster" maxlength="60" placeholder="e.g. Cluster 1">

            <label for="notes">Notes <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="notes" id="notes" maxlength="255">

            <button type="submit">Add Seat</button>
        </form>
    </div>
</div>

@foreach ($locations as $location)
    <div class="card-container-inline">
        <div class="card card-wide">
            <h2>{{ $location->name }} ({{ $location->workstations_count }} seats)</h2>
            <p style="color: var(--text-grey); font-size: 13px;">
                {{ $location->room_code }} · Block {{ $location->block }} ·
                <span class="status-badge {{ $location->gender === 'female' ? 'rejected' : 'pending' }}">{{ $location->genderLabel() }}</span>
            </p>
            <div class="card-divider"></div>

            @if ($location->workstations->isEmpty())
                <div class="empty-state">No seats yet.</div>
            @else
                <table class="recent-activity-table">
                    <thead>
                        <tr><th>Seat</th><th>Cluster</th><th>Status</th><th>Notes</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($location->workstations as $seat)
                            <tr>
                                <td>{{ $seat->seat_code }}</td>
                                <td>{{ $seat->cluster ?? '—' }}</td>
                                <td><span class="status-badge">{{ \App\Modules\Chloe\Models\Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}</span></td>
                                <td>{{ $seat->notes ?? '—' }}</td>
                                <td>
                                    @if ($seat->status !== 'occupied')
                                        <form method="POST" action="{{ route('workstation.cgs.catalog.seats.update', $seat) }}" style="display:inline-flex; gap:6px; flex-wrap:wrap;">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="seat_code" value="{{ $seat->seat_code }}" maxlength="20" required style="width:60px;">
                                            <input type="text" name="cluster" value="{{ $seat->cluster }}" maxlength="60" placeholder="Cluster" style="width:90px;">
                                            <select name="status">
                                                <option value="available" @selected($seat->status === 'available')>Available</option>
                                                <option value="reserved" @selected($seat->status === 'reserved')>Reserved</option>
                                                <option value="disabled" @selected($seat->status === 'disabled')>Under Maintenance</option>
                                            </select>
                                            <input type="text" name="notes" value="{{ $seat->notes }}" maxlength="255" placeholder="Notes" style="width:120px;">
                                            <button type="submit">Save</button>
                                        </form>
                                        <form method="POST" action="{{ route('workstation.cgs.catalog.seats.destroy', $seat) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit">Delete</button>
                                        </form>
                                    @else
                                        <span style="color: var(--text-grey);">Occupied — no changes until released</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endforeach
@endsection
