@extends('core::layouts.app')

@section('title', 'Workstation')

@php
    use App\Modules\Chloe\Models\LockerKey;
    use App\Modules\Chloe\Services\WorkstationAllocator;

    $lockerBadgeTone = [
        'requested' => 'pending', 'collected' => 'approved',
        'return_pending' => 'pending', 'returned' => 'draft',
    ];
@endphp

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     Same reasoning as the Block page's room grid: a fixed
     `repeat(4, minmax(0,1fr))` grid, not auto-fit/flex-grow, so a lone card
     in a short last row stays the same size as the rest and sits at the
     left instead of stretching to fill the row. --}}
<style>
    .ws-block-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        justify-items: stretch;
        gap: 24px;
        width: 100%;
    }

    @media (max-width: 1099px) {
        .ws-block-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (max-width: 799px) {
        .ws-block-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 519px) {
        .ws-block-grid { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    }

    .ws-block-card {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
        overflow: hidden;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
    }

    .ws-block-image {
        width: 100%;
        aspect-ratio: 4 / 3;
        object-fit: cover;
        display: block;
        background: var(--surface-sunken, #eef1f6);
    }

    .ws-block-body {
        height: 120px;
        padding: 18px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
</style>

<div class="card-container-inline">
    <x-core::page-header title="Workstation" />

    <div class="card card-wide">
        @if ($activeRequest)
            <p>
                Seat <b>{{ $activeRequest->workstation->seat_code }}</b>,
                {{ $activeRequest->workstation->location->name }} ({{ $activeRequest->workstation->location->block }})
                <span class="status-badge approved">Confirmed</span>
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                Since {{ $activeRequest->requested_at->format('j M Y, g:ia') }}
            </p>
            {{-- PART 2: the loan period's own end date -- candidacy expiry,
                 including any approved extension, since that IS the stored
                 date already. --}}
            @if ($loanValidUntil)
                <p style="color: var(--text-grey); font-size: 13px;">
                    Loan valid until {{ \Illuminate\Support\Carbon::parse($loanValidUntil)->format('j M Y') }}
                </p>
            @endif
            {{-- PART 3 #3c: the termination banner -- the seat stays
                 Occupied throughout the notice period, this is the only
                 sign on this page that the clock is running. --}}
            @if ($terminationNotice)
                <p style="font-weight: 600; color: var(--text-red, #c0392b);">
                    Your workstation loan has been terminated. Please vacate by
                    {{ $terminationNotice->vacate_by_date->format('j M Y') }}.
                </p>
            @endif

            <div class="card-divider"></div>

            {{-- STEP 5: no confirmation popup here -- choosing a reason IS
                 the deliberate step, enforced by disabling the button until
                 one is picked, not by a second "are you sure" dialog. --}}
            <h3 style="font-size: 14px;">Return Workstation</h3>
            <form method="POST" action="{{ route('workstation.return') }}" id="return-workstation-form">
                @csrf
                <label for="return-reason">Reason</label>
                <select name="reason" id="return-reason" required>
                    <option value="">Select a reason&hellip;</option>
                    @foreach (WorkstationAllocator::REASONS_STUDENT_RETURN as $reasonOption)
                        <option value="{{ $reasonOption }}" @selected(old('reason') === $reasonOption)>{{ $reasonOption }}</option>
                    @endforeach
                </select>

                <label for="return-remark">Remark (optional)</label>
                <textarea name="remark" id="return-remark" rows="2" maxlength="1000">{{ old('remark') }}</textarea>

                <button type="submit" id="return-workstation-submit" disabled>Return Workstation</button>
            </form>

            <script @cspNonce>
                (function () {
                    var select = document.getElementById('return-reason');
                    var submit = document.getElementById('return-workstation-submit');
                    if (! select || ! submit) return;

                    var sync = function () { submit.disabled = select.value === ''; };
                    select.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @else
            <div class="empty-state">
                You do not currently hold a workstation. Choose a block below to browse rooms and pick a seat.
            </div>
        @endif

        <div class="card-divider"></div>

        {{-- Locker Key. Its own section regardless of which of the 4 states
             applies, so "no confirmed seat yet" reads as this section's own
             state rather than a repeat of the empty-state above. A CANCELLED
             row (the student's own cancel, or the 7-day auto-cancel) is
             treated exactly like no row at all here -- it's history, not a
             status to show. --}}
        <h3 style="font-size: 14px; margin-top: 4px;">Locker Key</h3>

        @php
            $lockerKey = $activeRequest?->lockerKey;
            $lockerStatus = $lockerKey && in_array($lockerKey->status, [LockerKey::STATUS_REQUESTED, LockerKey::STATUS_COLLECTED])
                ? $lockerKey->status
                : null;
            $lockerNote = 'Please request your key here before collecting it at: Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00).';
        @endphp

        {{-- An outstanding return (from a seat that was released/reassigned
             while the key was already Collected) takes priority over
             whatever the key status is for the CURRENT seat, if any --
             it's a standing obligation, not something a new seat clears. --}}
        @if ($returnPendingKey)
            <p>
                <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_RETURN_PENDING] }}">Return Pending</span>
                @if ($returnPendingKey->isOverdueForReturn())
                    <span class="status-badge rejected">Overdue</span>
                @endif
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                Please return your locker key to the Centre for Graduate Studies (CGS) on or before
                {{ $returnPendingKey->return_due_at->format('j M Y') }}.
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00).
            </p>
            {{-- PART 4: this replaces the Request Key button a current seat
                 would otherwise show -- the branch above already never
                 renders one while a Return Pending key exists, this just
                 says so explicitly rather than leaving the absence
                 unexplained. --}}
            <p style="font-weight: 600; margin-top: 8px;">Please return your previous locker key first.</p>
        @elseif (! $activeRequest)
            <p style="color: var(--text-grey); font-size: 13px;">Select a workstation first to request your locker key.</p>
        @elseif ($lockerStatus === LockerKey::STATUS_COLLECTED)
            <p>
                <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_COLLECTED] }}">Collected</span>
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                Collected on {{ $lockerKey->collected_at->format('j M Y, g:ia') }}
            </p>
        @elseif ($lockerStatus === LockerKey::STATUS_REQUESTED)
            <p>
                <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_REQUESTED] }}">Pending Collection</span>
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                Requested on {{ $lockerKey->requested_at->format('j M Y, g:ia') }}.
                Please collect by {{ $lockerKey->collectByDate()->format('j M Y') }}.
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">{{ $lockerNote }}</p>

            <form method="POST" action="{{ route('workstation.locker-key.cancel') }}"
                  class="js-confirm-form" data-confirm="Do you want to cancel your locker key request?">
                @csrf
                <button type="submit">Cancel Request</button>
            </form>
        @else
            <p style="color: var(--text-grey); font-size: 13px;">{{ $lockerNote }}</p>

            <form method="POST" action="{{ route('workstation.locker-key.request') }}"
                  class="js-confirm-form" data-confirm="Do you want to request the locker key for Seat {{ $activeRequest->workstation->seat_code }}?">
                @csrf
                <button type="submit">Request Key</button>
            </form>
        @endif

        {{-- PART 4 #2. --}}
        <p style="color: var(--text-grey); font-size: 13px; margin-top: 8px;">
            By selecting a seat and confirming it by email, you agree to the
            <a href="{{ route('workstation.terms') }}">Postgraduate Workstation Term of Use</a>.
        </p>

        <script @cspNonce>
            document.querySelectorAll('.js-confirm-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (! confirm(form.dataset.confirm)) {
                        event.preventDefault();
                    }
                });
            });
        </script>
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h3>Postgraduate Workstation Availability</h3>
        <div class="card-divider"></div>

        @if (! $gender)
            <div class="empty-state">
                Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).
            </div>
        @else
            <p style="color: var(--text-grey); font-size: 13px;">Click a block to see its rooms.</p>
            <div class="ws-block-grid">
                @foreach ($blocks as $block)
                    <a href="{{ route('workstation.rooms', $block->code) }}" class="card ws-block-card">
                        <img src="{{ $block->photoUrl() }}" alt="{{ $block->name }}" loading="lazy" class="ws-block-image">
                        <div class="ws-block-body">
                            <b style="font-size:15px;">{{ $block->name }}</b>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
