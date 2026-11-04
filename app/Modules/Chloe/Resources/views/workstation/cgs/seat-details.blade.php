@extends('core::layouts.app')

@section('title', 'Seat ' . $workstation->seat_code)

@php
    use App\Modules\Chloe\Models\LockerKey;
    use App\Modules\Chloe\Models\StudyCandidacy;
    use App\Modules\Chloe\Models\Workstation;
    use App\Modules\Chloe\Services\WorkstationAllocator;
    use App\Modules\Chloe\Support\WorkingDays;

    $statusLabels = ['available' => 'Available', 'pending' => 'Pending', 'occupied' => 'Occupied', 'disabled' => 'Maintenance', 'reserved' => 'Reserved', 'awaiting_key_return' => 'Awaiting Key Return'];
    $statusTones = ['available' => 'approved', 'pending' => 'pending', 'occupied' => 'draft', 'disabled' => 'rejected', 'reserved' => 'draft', 'awaiting_key_return' => 'pending'];

    // PART 3 #4: a preview only -- the real vacate-by date is computed at
    // submission time by WorkstationAllocator::startTermination(), from
    // whichever day the notice actually starts on, not this page-load's.
    $previewVacateBy = WorkingDays::add(\Illuminate\Support\Carbon::now('Asia/Kuala_Lumpur')->startOfDay(), 5);

    // PART 2: the loan period's own end date -- only meaningful with a real occupant.
    $loanValidUntil = $occupant?->user
        ? StudyCandidacy::where('student_id', $occupant->user->id)->value('candidacy_expiry_date')
        : null;
@endphp

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     One card with divider lines between its five parts, per spec, rather
     than five boxed sections. The four action modals reuse Hani's
     examiner_admin reason-modal pattern (see that view's own doc): a
     native <dialog>, bound with addEventListener because script-src has
     no 'unsafe-inline' -- an inline onclick/onsubmit attribute is refused
     outright, nonce or not. --}}
<style>
    .seat-divider { height: 1px; background: var(--border-grey); margin: 20px 0; border: none; }
    .seat-fact-row { display: flex; flex-wrap: wrap; gap: 4px 24px; font-size: 14px; }
    .seat-fact-row > div { min-width: 160px; }
    .seat-fact-row b { display: block; font-size: 12px; color: var(--text-grey); font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 2px; }
    .seat-actions-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .seat-actions-row button { margin: 0; }
    .seat-actions-row .action-disabled-note { color: var(--text-grey); font-size: 13px; align-self: center; }

    .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .history-table th { text-align: left; padding: 8px 10px; color: var(--text-grey); border-bottom: 1px solid var(--border-grey); }
    .history-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-grey); vertical-align: top; }

    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 460px; width: 92vw; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; max-width: none; max-height: 82vh; overflow-y: auto; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-grey); }
    .reason-modal textarea { width: 100%; }
    .reason-modal .modal-note { font-size: 13px; color: var(--text-grey); background: var(--surface-sunken, #F8F9FB); padding: 10px 12px; border-radius: 8px; margin-top: 10px; }

    .seat-pick-search { width: 100%; margin-top: 4px; }
    .seat-pick-list { max-height: 220px; overflow-y: auto; border: 1px solid var(--border-grey); border-radius: 8px; margin-top: 8px; }
    .seat-pick-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border-grey); font-size: 13px; }
    .seat-pick-row:last-child { border-bottom: none; }
    .seat-pick-row input[type="radio"] { width: auto; margin: 0; }

    .student-pick-list { max-height: 220px; overflow-y: auto; border: 1px solid var(--border-grey); border-radius: 8px; margin-top: 8px; }
    .student-pick-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border-grey); font-size: 13px; cursor: pointer; }
    .student-pick-row:last-child { border-bottom: none; }
    .student-pick-row.is-selected { background: var(--light-blue-bg); }
    .student-pick-empty { padding: 10px; font-size: 13px; color: var(--text-grey); }
</style>

@include('chloe::workstation._live', ['liveKind' => 'cgs'])

<div class="card-container-inline">
    {{-- Back uses real browser history when there is one -- e.g. coming
         from a search/filter results grid -- so it returns there with the
         filters intact, rather than always landing on this seat's Room
         page regardless of how CGS actually got here. --}}
    <x-core::page-header title="Seat {{ $workstation->seat_code }}" :back="route('workstation.cgs.room', $workstation->workstation_location_id)">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide" data-live-region>
        {{-- 1. Seat --}}
        <h3 style="margin-top:0;">Seat</h3>
        <div class="seat-fact-row">
            <div><b>Block</b>{{ $workstation->location->block }}</div>
            <div><b>Room</b>
                {{ $workstation->location->room_code }}
                @if ($workstation->location->name && $workstation->location->name !== $workstation->location->room_code)
                    ({{ $workstation->location->name }})
                @endif
            </div>
            <div><b>Seat</b>{{ $workstation->seat_code }}</div>
            <div><b>Room Gender</b>{{ $workstation->location->genderLabel() }}</div>
            <div><b>Status</b><span class="status-badge {{ $statusTones[$workstation->status] ?? 'draft' }}">{{ $statusLabels[$workstation->status] ?? ucfirst($workstation->status) }}</span></div>
            {{-- PART 3 #4c: the badge -- the seat itself stays Occupied throughout the notice period. --}}
            @if ($terminationNotice)
                <div><b>Termination Notice</b><span class="status-badge rejected">Vacate by {{ $terminationNotice->vacate_by_date->format('j M Y') }}</span></div>
            @endif
        </div>

        <hr class="seat-divider">

        {{-- 2. Student --}}
        <h3>Student</h3>
        @if ($occupant)
            <div class="seat-fact-row">
                <div><b>Name</b>{{ $occupant->name }}</div>
                <div><b>Student ID</b>{{ $occupant->studentId }}</div>
                <div><b>Programme</b>{{ $occupant->programme ?? '—' }}</div>
                <div><b>Email</b><a href="mailto:{{ $occupant->email }}">{{ $occupant->email }}</a></div>
                <div><b>Gender</b>{{ $occupant->gender ? ucfirst($occupant->gender) : '—' }}</div>
                <div><b>Allocation Date</b>{{ $row['allocated_at']?->format('j M Y') ?? '—' }}</div>
                {{-- PART 2: candidacy expiry, including any approved extension -- that IS the stored date already. --}}
                @if ($loanValidUntil)
                    <div><b>Loan Valid Until</b>{{ \Illuminate\Support\Carbon::parse($loanValidUntil)->format('j M Y') }}</div>
                @endif
            </div>

            {{-- PART 2 #2: a standing obligation that follows the student,
                 not this seat -- shown here even though it belongs to a
                 different seat entirely. --}}
            @if ($elsewhereReturnPending)
                <p style="margin-top: 10px; font-size: 13px;">
                    Unreturned locker key: Seat {{ $elsewhereReturnPending['seat_code'] }}
                    (due {{ $elsewhereReturnPending['return_due_at']?->format('j M Y') ?? '—' }})
                </p>
            @endif
        @elseif ($previousOccupant)
            {{-- PART 2 #1: Awaiting Key Return (or any seat with no current
                 occupant but a Return Pending key) shows who it was, not
                 "No student assigned" -- contact details come from
                 StudentDirectory via the key's own occupant(), same as
                 everywhere else a former occupant is shown. --}}
            <p style="color: var(--text-grey); font-size: 12px; text-transform: uppercase; letter-spacing: .03em; font-weight: 600; margin-bottom: 8px;">Previous Occupant</p>
            <div class="seat-fact-row">
                <div><b>Name</b>{{ $previousOccupant->name }}</div>
                <div><b>Student ID</b>{{ $previousOccupant->studentId }}</div>
                <div><b>Programme</b>{{ $previousOccupant->programme ?? '—' }}</div>
                <div><b>Email</b><a href="mailto:{{ $previousOccupant->email }}">{{ $previousOccupant->email }}</a></div>
                <div><b>Gender</b>{{ $previousOccupant->gender ? ucfirst($previousOccupant->gender) : '—' }}</div>
                <div><b>Released</b>{{ $previousOccupantReleasedAt?->format('j M Y') ?? '—' }}</div>
            </div>
            <p style="margin-top: 10px; font-size: 13px;">
                Locker key not returned, due {{ $lockerKey->return_due_at->format('j M Y') }}
                @if ($lockerKey->isOverdueForReturn())
                    <span class="status-badge rejected">Overdue</span>
                @endif
            </p>
        @elseif ($workstation->status === Workstation::STATUS_OCCUPIED)
            <div class="empty-state">No student record.</div>
        @else
            <div class="empty-state">No student assigned.</div>
        @endif

        <hr class="seat-divider">

        {{-- 3. Locker key --}}
        <h3>Locker Key</h3>
        @if (! $lockerKey)
            <p style="color: var(--text-grey); font-size: 13px;">Not Requested.</p>
        @else
            @php
                $overdue = $lockerKey->status === LockerKey::STATUS_RETURN_PENDING && $lockerKey->isOverdueForReturn();
                $keyTones = ['requested' => 'pending', 'collected' => 'approved', 'return_pending' => 'pending', 'returned' => 'draft', 'cancelled' => 'draft', 'lost' => 'rejected'];
                $keyLabels = ['requested' => 'Pending Collection', 'collected' => 'Collected', 'return_pending' => 'Return Pending', 'returned' => 'Returned', 'cancelled' => 'Cancelled', 'lost' => 'Lost'];
            @endphp
            <div class="seat-fact-row">
                <div><b>Status</b>
                    <span class="status-badge {{ $keyTones[$lockerKey->status] ?? 'draft' }}">{{ $keyLabels[$lockerKey->status] ?? ucfirst($lockerKey->status) }}</span>
                    @if ($overdue) <span class="status-badge rejected">Overdue</span> @endif
                </div>
                <div><b>Requested</b>{{ $lockerKey->requested_at->format('j M Y') }}</div>
                @if ($lockerKey->status === LockerKey::STATUS_REQUESTED)
                    <div><b>Collect By</b>{{ $lockerKey->collectByDate()->format('j M Y') }}</div>
                @endif
                @if ($lockerKey->collected_at)
                    <div><b>Collected</b>{{ $lockerKey->collected_at->format('j M Y') }}</div>
                @endif
                @if ($lockerKey->return_due_at)
                    <div><b>Return Due</b>{{ $lockerKey->return_due_at->format('j M Y') }}</div>
                @endif
                @if ($lockerKey->returned_at)
                    <div><b>Returned</b>{{ $lockerKey->returned_at->format('j M Y') }}</div>
                @endif
            </div>

            <div class="seat-actions-row" style="margin-top:10px;">
                @if ($lockerKey->status === LockerKey::STATUS_REQUESTED)
                    <form method="POST" action="{{ route('workstation.cgs.seat.key-collected', $workstation) }}">
                        @csrf
                        <button type="submit">Mark as Collected</button>
                    </form>
                @elseif ($lockerKey->status === LockerKey::STATUS_RETURN_PENDING)
                    <form method="POST" action="{{ route('workstation.cgs.seat.key-returned', $workstation) }}">
                        @csrf
                        <button type="submit">Mark as Returned</button>
                    </form>
                @endif
            </div>
        @endif

        <hr class="seat-divider">

        {{-- 4. Actions --}}
        <h3>Actions</h3>
        <div class="seat-actions-row">
            @if ($workstation->status === Workstation::STATUS_AVAILABLE)
                <button type="button" data-open-modal="maintenance-modal">Set Maintenance</button>
                <button type="button" data-open-modal="assign-modal">Assign Student</button>
            @elseif ($workstation->status === Workstation::STATUS_OCCUPIED)
                @if ($terminationNotice)
                    {{-- PART 3 #4: a seat already mid-notice shows these two instead of Reassign/Release. --}}
                    <button type="button" data-open-modal="release-now-modal">Release now</button>
                    <button type="button" data-open-modal="withdraw-termination-modal">Withdraw Termination</button>
                @else
                    @if ($occupant)
                        <button type="button" data-open-modal="reassign-modal">Reassign</button>
                    @endif
                    <button type="button" data-open-modal="release-modal">Release</button>
                @endif
            @elseif ($workstation->status === Workstation::STATUS_AWAITING_KEY_RETURN)
                {{-- PART 1 #5/#6: cannot go to Maintenance from here -- the
                     button is simply not offered, with the reason stated
                     instead, and Release Hold is the only way out besides
                     the key itself coming back. --}}
                <span class="action-disabled-note">Return the key or use Release Hold first.</span>
                <button type="button" data-open-modal="release-hold-modal">Release Hold</button>
            @elseif ($workstation->status === Workstation::STATUS_PENDING)
                <span class="action-disabled-note">This seat is waiting for the student to confirm.</span>
            @elseif ($workstation->status === Workstation::STATUS_DISABLED)
                <form method="POST" action="{{ route('workstation.cgs.seat.restore', $workstation) }}">
                    @csrf
                    <button type="submit">Restore to Available</button>
                </form>
            @endif
        </div>

        <hr class="seat-divider">

        {{-- 5. History --}}
        <h3>History</h3>
        @if ($history->isEmpty())
            <div class="empty-state">No history recorded for this seat yet.</div>
        @else
            {{-- PART 3: actorLabel()/actionLabel()/beforeLabel()/afterLabel()/
                 remarkForDisplay() turn this row's own stored text into
                 what CGS actually reads -- see WorkstationHistory's own
                 doc for why none of it changes what is stored. --}}
            <div style="overflow-x:auto;">
                <table class="history-table">
                    <thead>
                        <tr><th>Date/Time</th><th>Actor</th><th>Action</th><th>Student</th><th>Reason</th><th>Remark</th><th>Before</th><th>After</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr>
                                <td>{{ $entry->created_at->format('j M Y, g:ia') }}</td>
                                <td>{{ $entry->actorLabel() }}</td>
                                <td>{{ $entry->actionLabel() }}</td>
                                <td>
                                    @if ($entry->student_name)
                                        {{ $entry->student_name }}<br>
                                        {{ $entry->student_matric_no ?? '—' }}<br>
                                        @if ($entry->student_email)
                                            <a href="mailto:{{ $entry->student_email }}">{{ $entry->student_email }}</a>
                                        @else
                                            —
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $entry->reason ?? '—' }}</td>
                                <td>{{ $entry->remarkForDisplay() ?? '—' }}</td>
                                <td>{{ $entry->beforeLabel() ?? '—' }}</td>
                                <td>{{ $entry->afterLabel() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

{{-- ===== Reassign modal (Occupied seats with a known student) ===== --}}
@if ($occupant)
<dialog id="reassign-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Reassign Seat {{ $workstation->seat_code }}</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.reassign', $workstation) }}" id="reassign-form">
            @csrf
            <p style="color: var(--text-grey); font-size: 13px;">
                Moves {{ $occupant->name }} from Seat {{ $workstation->seat_code }} to the seat chosen below.
            </p>
            @if ($row['key_status_value'] === 'collected')
                <div class="modal-note">The student's current locker key will be set to Return Pending.</div>
            @endif

            <label for="reassign-search">Choose a New Seat</label>
            <input type="text" id="reassign-search" class="seat-pick-search" placeholder="Search by seat number&hellip;">

            <div class="seat-pick-list" id="reassign-seat-list">
                @forelse ($availableSeatsForReassign as $seat)
                    <label class="seat-pick-row" data-seat-code="{{ $seat->seat_code }}">
                        <input type="radio" name="new_workstation_id" value="{{ $seat->id }}" required>
                        Seat {{ $seat->seat_code }}: {{ $seat->location->room_code }} (Block {{ $seat->location->block }})
                    </label>
                @empty
                    <div class="student-pick-empty">No available seats match this student's gender right now.</div>
                @endforelse
            </div>

            <label for="reassign-reason">Reason</label>
            <select name="reason" id="reassign-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_REASSIGN as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="reassign-remark">Remark (optional)</label>
            <textarea name="remark" id="reassign-remark" rows="2" maxlength="1000"></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="reassign-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>
@endif

{{-- ===== Release modal (Occupied seats) ===== --}}
<dialog id="release-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Release Seat {{ $workstation->seat_code }}</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.release', $workstation) }}" id="release-form">
            @csrf
            <p style="color: var(--text-grey); font-size: 13px;">
                @if ($occupant)
                    {{ $occupant->name }} will lose Seat {{ $workstation->seat_code }}
                    @if ($occupant->hasLogin) and will be notified by email. @else (a demo student -- notified at their demo email). @endif
                @else
                    This seat's current occupant is not tracked by the system and will not be notified.
                @endif
            </p>

            <label for="release-reason">Reason</label>
            <select name="reason" id="release-reason" required>
                <option value="">Select a reason&hellip;</option>
                <optgroup label="Termination (5 working days notice)">
                    @foreach (WorkstationAllocator::REASONS_TERMINATION as $reasonOption)
                        <option value="{{ $reasonOption }}" data-group="termination">{{ $reasonOption }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Immediate correction">
                    @foreach (WorkstationAllocator::REASONS_RELEASE_IMMEDIATE as $reasonOption)
                        <option value="{{ $reasonOption }}" data-group="immediate">{{ $reasonOption }}</option>
                    @endforeach
                </optgroup>
            </select>

            {{-- PART 3 #4: shown for the Termination group, with the preview
                 vacate-by date -- the real date is computed again at
                 submission time by startTermination(). --}}
            <div class="modal-note" id="release-termination-note" style="display:none;">
                The student will be notified and must vacate within 5 working days (by {{ $previewVacateBy->format('j M Y') }}). The seat will be released automatically on that date.
            </div>
            <div class="modal-note" id="release-no-email-note" style="display:none;">No email will be sent to the student.</div>

            <label for="release-remark">Remark (optional)</label>
            <textarea name="remark" id="release-remark" rows="2" maxlength="1000"></textarea>

            <label for="release-confirm">Type Seat {{ $workstation->seat_code }} to confirm</label>
            <input type="text" name="seat_confirmation" id="release-confirm" autocomplete="off" placeholder="{{ $workstation->seat_code }}">

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="release-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ===== Release now modal (seats already mid-notice) ===== --}}
<dialog id="release-now-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Release Seat {{ $workstation->seat_code }} Now</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.release-now', $workstation) }}" id="release-now-form">
            @csrf
            <p style="color: var(--text-grey); font-size: 13px;">
                Releases the seat immediately instead of waiting for the vacate-by date
                ({{ $terminationNotice?->vacate_by_date->format('j M Y') }}).
            </p>

            <label for="release-now-reason">Reason</label>
            <select name="reason" id="release-now-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_RELEASE_NOW as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="release-now-remark">Remark (optional)</label>
            <textarea name="remark" id="release-now-remark" rows="2" maxlength="1000"></textarea>

            <label for="release-now-confirm">Type Seat {{ $workstation->seat_code }} to confirm</label>
            <input type="text" name="seat_confirmation" id="release-now-confirm" autocomplete="off" placeholder="{{ $workstation->seat_code }}">

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="release-now-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ===== Withdraw Termination modal (seats already mid-notice) ===== --}}
<dialog id="withdraw-termination-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Withdraw Termination Notice for Seat {{ $workstation->seat_code }}</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.withdraw-termination', $workstation) }}" id="withdraw-termination-form">
            @csrf
            <p style="color: var(--text-grey); font-size: 13px;">
                The seat stays Occupied. The student will be told they may continue to use it.
            </p>

            <label for="withdraw-termination-reason">Reason</label>
            <select name="reason" id="withdraw-termination-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_WITHDRAW_TERMINATION as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="withdraw-termination-remark">Remark (optional)</label>
            <textarea name="remark" id="withdraw-termination-remark" rows="2" maxlength="1000"></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="withdraw-termination-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ===== Set Maintenance modal (Available seats) ===== --}}
<dialog id="maintenance-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Set Seat {{ $workstation->seat_code }} to Maintenance</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.maintenance', $workstation) }}" id="maintenance-form">
            @csrf
            <label for="maintenance-reason">Reason</label>
            <select name="reason" id="maintenance-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_MAINTENANCE as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="maintenance-remark">Remark (optional)</label>
            <textarea name="remark" id="maintenance-remark" rows="2" maxlength="1000"></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="maintenance-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ===== Release Hold modal (Awaiting Key Return seats) ===== --}}
<dialog id="release-hold-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Release Hold on Seat {{ $workstation->seat_code }}</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.release-hold', $workstation) }}" id="release-hold-form">
            @csrf
            <p style="color: var(--text-grey); font-size: 13px;">
                The seat becomes Available again and the outstanding locker key is marked Lost. No email or bell is sent.
            </p>

            <label for="release-hold-reason">Reason</label>
            <select name="reason" id="release-hold-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_RELEASE_HOLD as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="release-hold-remark">Remark (optional)</label>
            <textarea name="remark" id="release-hold-remark" rows="2" maxlength="1000"></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="release-hold-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ===== Assign Student modal (Available seats) ===== --}}
<dialog id="assign-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin:0;">Assign Student to Seat {{ $workstation->seat_code }}</h3>
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('workstation.cgs.seat.assign', $workstation) }}" id="assign-form">
            @csrf
            <input type="hidden" name="student_id" id="assign-student-id">

            <label for="assign-search">Search Student (name, ID or email)</label>
            <input type="text" id="assign-search" class="seat-pick-search" placeholder="Start typing&hellip;"
                   data-search-url="{{ route('workstation.cgs.seat.students.search', $workstation) }}">

            <div class="student-pick-list" id="assign-student-list">
                <div class="student-pick-empty">Type at least one character to search.</div>
            </div>

            <p id="assign-selected-name" style="font-size: 13px; font-weight: 600; margin-top: 10px;"></p>

            <label for="assign-reason">Reason</label>
            <select name="reason" id="assign-reason" required>
                <option value="">Select a reason&hellip;</option>
                @foreach (WorkstationAllocator::REASONS_ASSIGN as $reasonOption)
                    <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                @endforeach
            </select>

            <label for="assign-remark">Remark (optional)</label>
            <textarea name="remark" id="assign-remark" rows="2" maxlength="1000"></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" id="assign-submit" disabled>Confirm</button>
            </div>
        </form>
    </div>
</dialog>

<script @cspNonce>
    (function () {
        document.querySelectorAll('[data-browser-back]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (window.history.length > 1 && document.referrer) {
                    event.preventDefault();
                    window.history.back();
                }
            });
        });

        document.querySelectorAll('[data-open-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                var modal = document.getElementById(button.dataset.openModal);
                if (modal) modal.showModal();
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = button.closest('dialog');
                if (dialog) dialog.close();
            });
        });

        // Confirm stays disabled until a reason is chosen -- same rule on
        // every one of these modals (plus Release's extra seat-number gate).
        function syncOnReason(selectId, submitId, extraCheck) {
            var select = document.getElementById(selectId);
            var submit = document.getElementById(submitId);
            if (! select || ! submit) return;
            var sync = function () {
                submit.disabled = select.value === '' || (extraCheck ? ! extraCheck() : false);
            };
            select.addEventListener('change', sync);
            return sync;
        }

        var reassignSync = syncOnReason('reassign-reason', 'reassign-submit', function () {
            return !! document.querySelector('#reassign-seat-list input[type="radio"]:checked');
        });
        if (reassignSync) {
            document.querySelectorAll('#reassign-seat-list input[type="radio"]').forEach(function (radio) {
                radio.addEventListener('change', reassignSync);
            });
        }

        syncOnReason('maintenance-reason', 'maintenance-submit');
        syncOnReason('release-hold-reason', 'release-hold-submit');

        var assignSync = syncOnReason('assign-reason', 'assign-submit', function () {
            return document.getElementById('assign-student-id').value !== '';
        });

        // Release: both the reason AND the typed seat number gate Confirm.
        (function () {
            var reasonSelect = document.getElementById('release-reason');
            var confirmInput = document.getElementById('release-confirm');
            var submit = document.getElementById('release-submit');
            var noEmailNote = document.getElementById('release-no-email-note');
            var terminationNote = document.getElementById('release-termination-note');
            if (! reasonSelect || ! confirmInput || ! submit) return;

            var seatCode = @json($workstation->seat_code);

            var sync = function () {
                submit.disabled = reasonSelect.value === '' || confirmInput.value !== seatCode;
                var selectedGroup = reasonSelect.selectedOptions[0] ? reasonSelect.selectedOptions[0].dataset.group : null;
                terminationNote.style.display = selectedGroup === 'termination' ? 'block' : 'none';
                noEmailNote.style.display = reasonSelect.value === @json(\App\Modules\Chloe\Services\WorkstationAllocator::REASON_RELEASE_NO_EMAIL) ? 'block' : 'none';
            };
            reasonSelect.addEventListener('change', sync);
            confirmInput.addEventListener('input', sync);
            sync();
        })();

        syncOnReason('withdraw-termination-reason', 'withdraw-termination-submit');

        (function () {
            var reasonSelect = document.getElementById('release-now-reason');
            var confirmInput = document.getElementById('release-now-confirm');
            var submit = document.getElementById('release-now-submit');
            if (! reasonSelect || ! confirmInput || ! submit) return;

            var seatCode = @json($workstation->seat_code);

            var sync = function () {
                submit.disabled = reasonSelect.value === '' || confirmInput.value !== seatCode;
            };
            reasonSelect.addEventListener('change', sync);
            confirmInput.addEventListener('input', sync);
            sync();
        })();

        // Reassign: client-side filter over the pre-rendered Available-seat list.
        var reassignSearch = document.getElementById('reassign-search');
        if (reassignSearch) {
            reassignSearch.addEventListener('input', function () {
                var needle = reassignSearch.value.trim().toLowerCase();
                document.querySelectorAll('#reassign-seat-list .seat-pick-row').forEach(function (row) {
                    var code = (row.dataset.seatCode || '').toLowerCase();
                    row.style.display = (needle === '' || code.indexOf(needle) !== -1) ? '' : 'none';
                });
            });
        }

        // Assign: live AJAX search against the gender-matched, seatless
        // student list -- connect-src is 'self', so a same-origin fetch is
        // allowed under the CSP without any extra allow-listing.
        var assignSearch = document.getElementById('assign-search');
        var assignList = document.getElementById('assign-student-list');
        var assignStudentId = document.getElementById('assign-student-id');
        var assignSelectedName = document.getElementById('assign-selected-name');

        if (assignSearch) {
            var runSearch = function () {
                var q = assignSearch.value.trim();
                var url = assignSearch.dataset.searchUrl + (q ? '?q=' + encodeURIComponent(q) : '');

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (students) {
                        assignList.innerHTML = '';

                        if (students.length === 0) {
                            assignList.innerHTML = '<div class="student-pick-empty">No matching students.</div>';
                            return;
                        }

                        students.forEach(function (student) {
                            var row = document.createElement('div');
                            row.className = 'student-pick-row';
                            row.textContent = student.name + ' — ' + (student.matric_no || 'No ID') + ' (' + student.email + ')';
                            row.addEventListener('click', function () {
                                document.querySelectorAll('#assign-student-list .student-pick-row').forEach(function (r) {
                                    r.classList.remove('is-selected');
                                });
                                row.classList.add('is-selected');
                                assignStudentId.value = student.id;
                                assignSelectedName.textContent = 'Selected: ' + student.name;
                                if (assignSync) assignSync();
                            });
                            assignList.appendChild(row);
                        });
                    });
            };

            assignSearch.addEventListener('input', function () {
                assignStudentId.value = '';
                assignSelectedName.textContent = '';
                if (assignSync) assignSync();
                runSearch();
            });
        }
    })();
</script>
@endsection
