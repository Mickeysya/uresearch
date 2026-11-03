{{--
    One seat, as a row card -- used by the Room page (one room's seats) and
    the landing page's search/filter results grid, so both read from the
    same $row array shape built by WorkstationManagementController::
    presentSeat() and never drift apart. No <style> here on purpose: this
    partial is included once per seat, and a <style> tag would be repeated
    once per row with it -- each parent view carries the .cgs-seat-card
    rules itself, the same "page owns its own CSS" convention every other
    Workstation view already follows.

    Expects $row (see presentSeat()'s return shape).
--}}
@php
    $statusLabels = ['available' => 'Available', 'pending' => 'Pending', 'occupied' => 'Occupied', 'disabled' => 'Maintenance', 'reserved' => 'Reserved', 'awaiting_key_return' => 'Awaiting Key Return'];
    $statusTones = ['available' => 'approved', 'pending' => 'pending', 'occupied' => 'draft', 'disabled' => 'rejected', 'reserved' => 'draft', 'awaiting_key_return' => 'pending'];
    $keyTones = ['requested' => 'pending', 'collected' => 'approved', 'return_pending' => 'pending'];

    // PART 2 #3: the same three-line block either way -- the CURRENT
    // occupant for an Occupied seat, or the PREVIOUS one for Awaiting Key
    // Return (and, defensively, any other seat that still carries a
    // former occupant but isn't Occupied itself).
    $showPreviousOccupant = $row['status'] !== 'occupied' && $row['is_former_student'];
@endphp
<div class="cgs-seat-card">
    <img src="{{ $row['seat']->location->seatIconUrl() }}" alt="" aria-hidden="true" class="cgs-seat-image">

    <div class="cgs-seat-text">
        <div class="cgs-seat-title">Seat {{ $row['seat_code'] }}</div>
        <div class="cgs-seat-sub">Block {{ $row['block'] }} &middot; {{ $row['room_code'] }}</div>

        <div class="cgs-seat-badges">
            @if ($row['status'] === 'awaiting_key_return')
                <span class="status-badge {{ $statusTones['awaiting_key_return'] }}">Awaiting Key Return</span>
                @if ($row['key_overdue'])
                    <span class="status-badge rejected">Overdue</span>
                @endif
            @else
                <span class="status-badge {{ $statusTones[$row['status']] ?? 'draft' }}">{{ $statusLabels[$row['status']] ?? ucfirst($row['status']) }}</span>

                @if ($row['status'] === 'occupied' && $row['key_status_value'])
                    <span class="status-badge {{ $keyTones[$row['key_status_value']] ?? 'draft' }}">{{ $row['key_status_label'] }}</span>
                    @if ($row['key_overdue'])
                        <span class="status-badge rejected">Overdue</span>
                    @endif
                @endif

                {{-- PART 3 #4c: the seat itself stays Occupied throughout the notice period. --}}
                @if ($row['termination_notice'])
                    <span class="status-badge rejected">Vacate by {{ $row['termination_notice']->vacate_by_date->format('j M Y') }}</span>
                @endif
            @endif
        </div>

        @if ($row['status'] === 'occupied')
            <div class="cgs-seat-lines">
                <div class="cgs-seat-line" title="{{ $row['student_name'] ?? 'No student record' }}">{{ $row['student_name'] ?? 'No student record' }}</div>
                <div class="cgs-seat-line" title="Student ID: {{ $row['student_matric'] ?? '—' }}">Student ID: {{ $row['student_matric'] ?? '—' }}</div>
                <div class="cgs-seat-line" title="{{ $row['student_email'] ?? '—' }}">{{ $row['student_email'] ?? '—' }}</div>
            </div>
        @elseif ($showPreviousOccupant)
            <div class="cgs-seat-sub-label">Previous occupant</div>
            <div class="cgs-seat-lines">
                <div class="cgs-seat-line" title="{{ $row['student_name'] }}">{{ $row['student_name'] }}</div>
                <div class="cgs-seat-line" title="Student ID: {{ $row['student_matric'] ?? '—' }}">Student ID: {{ $row['student_matric'] ?? '—' }}</div>
                <div class="cgs-seat-line" title="{{ $row['student_email'] ?? '—' }}">{{ $row['student_email'] ?? '—' }}</div>
            </div>
        @endif
    </div>

    <a href="{{ route('workstation.cgs.seat', $row['seat']) }}" class="cgs-seat-button"><button type="button">View Details</button></a>
</div>
