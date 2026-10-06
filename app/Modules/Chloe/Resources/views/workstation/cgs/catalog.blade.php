@extends('core::layouts.app')

@section('title', 'Workstation Catalogue')

@section('content')
@php use App\Modules\Chloe\Models\Workstation; @endphp
@include('chloe::partials.styles')

@php
    $seatBadge = ['available' => 'approved', 'occupied' => 'rejected', 'reserved' => 'draft', 'disabled' => 'pending'];
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Seat Catalogue"
                         subtitle="The rooms and seats students can book. An occupied seat cannot be edited until it is released.">
        <a href="{{ route('workstation.cgs.operations') }}" class="btn-secondary">Workstation Management</a>
    </x-core::page-header>

    <div class="chloe-stack">
        <div class="card card-wide">
            <h3>Add a room</h3>
            <form method="POST" action="{{ route('workstation.cgs.catalog.locations.store') }}" class="app-form">
                @csrf
                <label for="block">Block</label>
                <input type="text" name="block" id="block" required maxlength="10" placeholder="e.g. J2"
                       value="{{ old('block') }}" class="@error('block') is-invalid @enderror">
                @error('block') <p class="field-error">{{ $message }}</p> @enderror

                <label for="room_code">Room code</label>
                <input type="text" name="room_code" id="room_code" required maxlength="30" placeholder="e.g. N2-03-01-02"
                       value="{{ old('room_code') }}" class="@error('room_code') is-invalid @enderror">
                @error('room_code') <p class="field-error">{{ $message }}</p> @enderror

                <label for="gender">Designated for</label>
                <select name="gender" id="gender" required class="@error('gender') is-invalid @enderror">
                    <option value="">Select</option>
                    <option value="male" @selected(old('gender') === 'male')>Male students</option>
                    <option value="female" @selected(old('gender') === 'female')>Female students</option>
                </select>
                @error('gender') <p class="field-error">{{ $message }}</p> @enderror

                <label for="name">Room label</label>
                <input type="text" name="name" id="name" required maxlength="150" placeholder="e.g. PG LAB MALE 1.9"
                       value="{{ old('name') }}" class="@error('name') is-invalid @enderror">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="description">Description <span style="color: var(--text-grey)">(optional)</span></label>
                <input type="text" name="description" id="description" maxlength="255" value="{{ old('description') }}">

                <div class="chloe-actions"><button type="submit">Add room</button></div>
            </form>
        </div>

        <div class="card card-wide">
            <h3>Add a seat</h3>
            <form method="POST" action="{{ route('workstation.cgs.catalog.seats.store') }}" class="app-form">
                @csrf
                <label for="workstation_location_id">Room</label>
                <select name="workstation_location_id" id="workstation_location_id" required>
                    <option value="">Select</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(old('workstation_location_id') == $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>

                <label for="seat_code">Seat number</label>
                <input type="text" name="seat_code" id="seat_code" required maxlength="20" placeholder="e.g. 17"
                       value="{{ old('seat_code') }}" class="@error('seat_code') is-invalid @enderror">
                @error('seat_code') <p class="field-error">{{ $message }}</p> @enderror

                <label for="cluster">Cluster <span style="color: var(--text-grey)">(optional, the desk group this seat belongs to)</span></label>
                <input type="text" name="cluster" id="cluster" maxlength="60" placeholder="e.g. Cluster 1" value="{{ old('cluster') }}">

                <label for="notes">Notes <span style="color: var(--text-grey)">(optional)</span></label>
                <input type="text" name="notes" id="notes" maxlength="255" value="{{ old('notes') }}">

                <div class="chloe-actions"><button type="submit">Add seat</button></div>
            </form>
        </div>

        @foreach ($locations as $location)
            <div class="card card-wide">
                <h3>{{ $location->name }}</h3>
                <p class="queue-meta">
                    {{ $location->room_code }} · Block {{ $location->block }} ·
                    <span class="status-badge draft">{{ $location->genderLabel() }}</span> ·
                    {{ $location->workstations_count }} {{ Str::plural('seat', $location->workstations_count) }}
                </p>

                @if ($location->workstations->isEmpty())
                    <div class="empty-state">No seats in this room yet. Add one above.</div>
                @else
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Seat</th><th>Status</th><th>Edit</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($location->workstations as $seat)
                                    <tr>
                                        <td class="rpd-num">{{ $seat->seat_code }}</td>
                                        <td><span class="status-badge {{ $seatBadge[$seat->status] ?? 'draft' }}">{{ Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}</span></td>
                                        @if ($seat->status !== Workstation::STATUS_OCCUPIED)
                                            <td>
                                                <form method="POST" action="{{ route('workstation.cgs.catalog.seats.update', $seat) }}" class="rpd-inline-form chloe-inline">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="seat_code" value="{{ $seat->seat_code }}" maxlength="20" required class="is-narrow" aria-label="Seat number">
                                                    <input type="text" name="cluster" value="{{ $seat->cluster }}" maxlength="60" placeholder="Cluster" aria-label="Cluster">
                                                    <select name="status" aria-label="Status">
                                                        <option value="available" @selected($seat->status === 'available')>Available</option>
                                                        <option value="reserved" @selected($seat->status === 'reserved')>Reserved</option>
                                                        <option value="disabled" @selected($seat->status === 'disabled')>Under maintenance</option>
                                                    </select>
                                                    <input type="text" name="notes" value="{{ $seat->notes }}" maxlength="255" placeholder="Notes" aria-label="Notes">
                                                    <button type="submit" class="btn-secondary">Save</button>
                                                </form>
                                            </td>
                                            <td class="rpd-actions">
                                                <form method="POST" action="{{ route('workstation.cgs.catalog.seats.destroy', $seat) }}" class="rpd-inline-form">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-reject">Delete</button>
                                                </form>
                                            </td>
                                        @else
                                            <td colspan="2" class="queue-meta">
                                                {{ $seat->cluster ? $seat->cluster.'. ' : '' }}Occupied, so it cannot be changed until released.
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
