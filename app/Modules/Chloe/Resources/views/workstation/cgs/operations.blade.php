@extends('core::layouts.app')

@section('title', 'Workstation Management')

@section('content')
<h2 style="color: var(--navy);">Workstation Management</h2>

<div class="stat-cards-row">
    <div class="stat-card accent-green">
        <div class="stat-number">{{ $availableCount }}</div>
        <div class="stat-label">Available Workstations</div>
    </div>
    <div class="stat-card accent-red">
        <div class="stat-number">{{ $occupiedCount }}</div>
        <div class="stat-label">Occupied Workstations</div>
    </div>
    <div class="stat-card accent-gold">
        <div class="stat-number">{{ $pendingLockerKeys->count() }}</div>
        <div class="stat-label">Pending Locker Key Collection</div>
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Available Workstations by Room</h2>
        <div class="card-divider"></div>

        <table class="recent-activity-table">
            <thead>
                <tr><th>Room</th><th>Block</th><th>Gender</th><th>Available</th></tr>
            </thead>
            <tbody>
                @foreach ($rooms as $room)
                    <tr>
                        <td>{{ $room->name }} <span style="color: var(--text-grey);">({{ $room->room_code }})</span></td>
                        <td>{{ $room->block }}</td>
                        <td><span class="status-badge {{ $room->gender === 'female' ? 'rejected' : 'pending' }}">{{ $room->genderLabel() }}</span></td>
                        <td>{{ $room->available_count }} / {{ $room->workstations_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Pending Locker Key Collection</h2>
        <div class="card-divider"></div>

        @if ($pendingLockerKeys->isEmpty())
            <div class="empty-state">Nothing pending collection.</div>
        @else
            <table class="recent-activity-table">
                <thead>
                    <tr><th>Student</th><th>Seat</th><th>Requested</th><th>Days Waiting</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($pendingLockerKeys as $lockerKey)
                        <tr>
                            <td>{{ $lockerKey->student->name ?? '—' }}</td>
                            <td>{{ $lockerKey->workstationRequest->workstation->seat_code ?? '—' }}</td>
                            <td>{{ $lockerKey->requested_at->format('j M Y') }}</td>
                            <td>{{ (int) $lockerKey->requested_at->diffInDays(now()) }}</td>
                            <td>
                                @if ($lockerKey->isOverdue())
                                    <span class="status-badge rejected">Overdue</span>
                                @else
                                    <span class="status-badge pending">Waiting</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('workstation.cgs.locker-key.collected', $lockerKey) }}">
                                    @csrf
                                    <button type="submit">Mark Collected</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Seats — by Room</h2>
        <p style="color: var(--text-grey); font-size: 13px;">
            Force-assigning or force-releasing a seat is an exception path for cases the normal student flow cannot
            handle. Every use is logged to the audit trail with the reason given. Grouped by room — click a room to
            open it. Most seats already occupied when the catalogue was set up have no known occupant, matching
            CGS's own seat map, which recorded occupancy but not names.
        </p>
        <div class="card-divider"></div>

        @foreach ($rooms as $room)
            <details style="margin-bottom: 10px;">
                <summary style="cursor:pointer; font-weight:600;">
                    {{ $room->name }} ({{ $room->room_code }}) — {{ $room->available_count }} / {{ $room->workstations_count }} available
                </summary>

                <table class="recent-activity-table" style="margin-top:8px;">
                    <thead>
                        <tr><th>Seat</th><th>Cluster</th><th>Status</th><th>Occupant</th><th>Locker Key</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($room->workstations as $seat)
                            <tr>
                                <td>{{ $seat->seat_code }}</td>
                                <td>{{ $seat->cluster ?? '—' }}</td>
                                <td><span class="status-badge">{{ \App\Modules\Chloe\Models\Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}</span></td>
                                <td>
                                    @if ($seat->status === 'occupied')
                                        {{ $seat->activeRequest->student->name ?? 'Unknown occupant' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if ($seat->activeRequest?->lockerKey)
                                        <span class="status-badge">{{ ucfirst($seat->activeRequest->lockerKey->status) }}</span>
                                        @if ($seat->activeRequest->lockerKey->status === 'collected')
                                            <form method="POST" action="{{ route('workstation.cgs.locker-key.returned', $seat->activeRequest->lockerKey) }}" style="display:inline;">
                                                @csrf
                                                <button type="submit">Mark Returned</button>
                                            </form>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if ($seat->status === 'occupied')
                                        <form method="POST" action="{{ route('workstation.cgs.force-release', $seat) }}" style="display:flex; gap:6px; flex-wrap:wrap;">
                                            @csrf
                                            <input type="text" name="reason" placeholder="Reason (required)" required maxlength="255">
                                            <button type="submit">Force-Release</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('workstation.cgs.force-assign', $seat) }}" style="display:flex; gap:6px; flex-wrap:wrap;">
                                            @csrf
                                            <select name="student_id" required>
                                                <option value="">— Select student —</option>
                                                @foreach ($students as $student)
                                                    <option value="{{ $student->id }}">{{ $student->name }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="reason" placeholder="Reason (required)" required maxlength="255">
                                            <button type="submit">Force-Assign</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endforeach
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Student Genders</h2>
        <p style="color: var(--text-grey); font-size: 13px;">
            Rooms are gender-designated, and a student sets their own gender the first time they visit Workstation.
            Correct it here for exceptional cases.
        </p>
        <div class="card-divider"></div>

        <table class="recent-activity-table">
            <thead>
                <tr><th>Student</th><th>Gender</th><th>Set / Change</th></tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    <tr>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->gender ? ucfirst($student->gender) : '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('workstation.cgs.gender.set', $student) }}" style="display:flex; gap:6px;">
                                @csrf
                                <select name="gender" required>
                                    <option value="male" @selected($student->gender === 'male')>Male</option>
                                    <option value="female" @selected($student->gender === 'female')>Female</option>
                                </select>
                                <button type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<p><a href="{{ route('workstation.cgs.catalog') }}">Manage the seat catalogue →</a></p>
@endsection
