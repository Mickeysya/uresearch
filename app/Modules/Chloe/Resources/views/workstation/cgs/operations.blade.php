@extends('core::layouts.app')

@section('title', 'Workstation Management')

@section('content')
@php use App\Modules\Chloe\Models\Workstation; @endphp
@include('chloe::partials.styles')

@php
    $seatBadge = ['available' => 'approved', 'occupied' => 'rejected', 'reserved' => 'draft', 'disabled' => 'pending'];
    $lockerBadge = ['requested' => 'pending', 'collected' => 'approved', 'returned' => 'draft'];
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Workstation Management"
                         subtitle="Seat occupancy across every room, locker keys waiting to be collected, and the exception tools for CGS.">
        <a href="{{ route('workstation.cgs.catalog') }}" class="btn-secondary">Seat catalogue</a>
    </x-core::page-header>

    <div class="sdash-stats chloe-stats">
        <div class="sdash-stat tone-green">
            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'check'])</span>
                <span class="sdash-stat-label">Available workstations</span>
            </div>
            <div class="sdash-stat-value">{{ $availableCount }}</div>
        </div>
        <div class="sdash-stat tone-purple">
            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'people'])</span>
                <span class="sdash-stat-label">Occupied workstations</span>
            </div>
            <div class="sdash-stat-value">{{ $occupiedCount }}</div>
        </div>
        <div class="sdash-stat tone-orange">
            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'clock'])</span>
                <span class="sdash-stat-label">Locker keys to collect</span>
            </div>
            <div class="sdash-stat-value">{{ $pendingLockerKeys->count() }}</div>
        </div>
    </div>

    <div class="chloe-stack">
        <div class="card card-wide">
            <h3>Availability by room</h3>

            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Room</th><th>Block</th><th>Gender</th><th>Available</th></tr></thead>
                    <tbody>
                        @foreach ($rooms as $room)
                            <tr>
                                <td>{{ $room->name }} @if ($room->room_code !== $room->name)<span class="queue-meta">{{ $room->room_code }}</span>@endif</td>
                                <td>{{ $room->block }}</td>
                                <td><span class="status-badge draft">{{ $room->genderLabel() }}</span></td>
                                <td class="rpd-num">{{ $room->available_count }} / {{ $room->workstations_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-wide">
            <h3>Locker keys waiting for collection</h3>

            @if ($pendingLockerKeys->isEmpty())
                <div class="empty-state">No locker keys are waiting to be collected.</div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Seat</th><th>Requested</th><th>Waiting</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($pendingLockerKeys as $lockerKey)
                                @php($waiting = (int) $lockerKey->requested_at->diffInDays(now()))
                                <tr>
                                    <td>{{ $lockerKey->student->name ?? '—' }}</td>
                                    <td>{{ $lockerKey->workstationRequest->workstation->seat_code ?? '—' }}</td>
                                    <td class="rpd-num">{{ $lockerKey->requested_at->format('j M Y') }}</td>
                                    <td class="rpd-num tone-{{ $lockerKey->isOverdue() ? 'critical' : 'info' }}">{{ $waiting }}d</td>
                                    <td>
                                        <span class="status-badge {{ $lockerKey->isOverdue() ? 'rejected' : 'pending' }}">{{ $lockerKey->isOverdue() ? 'Overdue' : 'Waiting' }}</span>
                                    </td>
                                    <td class="rpd-actions">
                                        <form method="POST" action="{{ route('workstation.cgs.locker-key.collected', $lockerKey) }}" class="rpd-inline-form">
                                            @csrf
                                            <button type="submit">Mark collected</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card card-wide">
            <h3>Seats by room</h3>
            <p class="queue-meta">
                Force-assign and force-release are for cases the normal student flow cannot handle, and every use is
                logged with its reason. Most seats already occupied when the catalogue was set up have no known
                occupant, matching CGS's own seat map, which recorded occupancy but not names.
            </p>

            @foreach ($rooms as $room)
                <details class="chloe-room">
                    <summary>
                        <span>{{ $room->name }} @if ($room->room_code !== $room->name)<span class="queue-meta">{{ $room->room_code }}</span>@endif</span>
                        <span class="chloe-tile-meta">{{ $room->available_count }} of {{ $room->workstations_count }} available</span>
                    </summary>

                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Seat</th><th>Cluster</th><th>Status</th><th>Occupant</th><th>Locker key</th><th>Action</th></tr></thead>
                            <tbody>
                                @foreach ($room->workstations as $seat)
                                    @php($lockerKey = $seat->activeRequest?->lockerKey)
                                    <tr>
                                        <td class="rpd-num">{{ $seat->seat_code }}</td>
                                        <td>{{ $seat->cluster ?? '—' }}</td>
                                        <td><span class="status-badge {{ $seatBadge[$seat->status] ?? 'draft' }}">{{ Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}</span></td>
                                        <td>
                                            @if ($seat->status === Workstation::STATUS_OCCUPIED)
                                                {{ $seat->activeRequest->student->name ?? 'Unknown occupant' }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($lockerKey)
                                                <div class="chloe-inline">
                                                    <span class="status-badge {{ $lockerBadge[$lockerKey->status] ?? 'draft' }}">{{ ucfirst($lockerKey->status) }}</span>
                                                    @if ($lockerKey->status === 'collected')
                                                        <form method="POST" action="{{ route('workstation.cgs.locker-key.returned', $lockerKey) }}" class="rpd-inline-form">
                                                            @csrf
                                                            <button type="submit" class="btn-secondary">Mark returned</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($seat->status === Workstation::STATUS_OCCUPIED)
                                                <form method="POST" action="{{ route('workstation.cgs.force-release', $seat) }}" class="rpd-inline-form chloe-inline">
                                                    @csrf
                                                    <input type="text" name="reason" placeholder="Reason" aria-label="Reason for releasing seat {{ $seat->seat_code }}" required maxlength="255">
                                                    <button type="submit" class="btn-reject">Force-release</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('workstation.cgs.force-assign', $seat) }}" class="rpd-inline-form chloe-inline">
                                                    @csrf
                                                    <select name="student_id" aria-label="Student to assign to seat {{ $seat->seat_code }}" required>
                                                        <option value="">Select student</option>
                                                        @foreach ($students as $student)
                                                            <option value="{{ $student->id }}">{{ $student->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="text" name="reason" placeholder="Reason" aria-label="Reason for assigning seat {{ $seat->seat_code }}" required maxlength="255">
                                                    <button type="submit" class="btn-secondary">Force-assign</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        </div>

        <div class="card card-wide">
            <h3>Student genders</h3>
            <p class="queue-meta">
                Rooms are gender-designated, and students set their own gender the first time they open Workstation.
                Correct it here for exceptional cases.
            </p>

            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Gender</th><th>Change</th></tr></thead>
                    <tbody>
                        @foreach ($students as $student)
                            <tr>
                                <td>{{ $student->name }}</td>
                                <td>{{ $student->gender ? ucfirst($student->gender) : '—' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('workstation.cgs.gender.set', $student) }}" class="rpd-inline-form chloe-inline">
                                        @csrf
                                        <select name="gender" aria-label="Gender for {{ $student->name }}" required>
                                            <option value="male" @selected($student->gender === 'male')>Male</option>
                                            <option value="female" @selected($student->gender === 'female')>Female</option>
                                        </select>
                                        <button type="submit" class="btn-secondary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
