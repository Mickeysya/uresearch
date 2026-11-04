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

<link rel="stylesheet" href="{{ asset('css/chloe-workstation.css') }}">

@include('chloe::workstation._live', ['liveKind' => 'student'])

<div class="card-container-inline" data-live-region>
    <x-core::page-header title="Workstation" />

    <div class="card card-wide">
        <div class="ws-status-grid">
            {{-- Your workstation --}}
            <section class="ws-panel" aria-labelledby="ws-seat-label">
                <h2 class="ws-panel-label" id="ws-seat-label">Your workstation</h2>

                @if ($activeRequest)
                    <div class="ws-seat-hero">
                        <strong>Seat {{ $activeRequest->workstation->seat_code }}</strong>
                        <span class="status-badge approved">Confirmed</span>
                    </div>
                    <p class="ws-muted">
                        {{ $activeRequest->workstation->location->name }} · Block {{ $activeRequest->workstation->location->block }}
                    </p>
                    <p class="ws-muted">Since {{ $activeRequest->requested_at->format('j M Y, g:ia') }}</p>
                    {{-- PART 2: the loan period's own end date -- candidacy expiry,
                         including any approved extension, since that IS the stored
                         date already. --}}
                    @if ($loanValidUntil)
                        <p class="ws-muted">Loan valid until {{ \Illuminate\Support\Carbon::parse($loanValidUntil)->format('j M Y') }}</p>
                    @endif
                    {{-- PART 3 #3c: the termination banner -- the seat stays
                         Occupied throughout the notice period, this is the only
                         sign on this page that the clock is running. --}}
                    @if ($terminationNotice)
                        <div class="ws-notice ws-notice-danger" role="alert" style="margin: var(--space-4) 0 0;">
                            Your workstation loan has been terminated. Please vacate by
                            {{ $terminationNotice->vacate_by_date->format('j M Y') }}.
                        </div>
                    @endif

                    {{-- STEP 5: no confirmation popup here -- choosing a reason IS
                         the deliberate step, enforced by disabling the button until
                         one is picked, not by a second "are you sure" dialog. --}}
                    <form method="POST" action="{{ route('workstation.return') }}" id="return-workstation-form" class="ws-return-form">
                        @csrf
                        <h3>Return Workstation</h3>
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
                    <div class="ws-empty">
                        <span class="ws-empty-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                        </span>
                        <div>
                            <p class="ws-empty-title">No workstation yet</p>
                            <p class="ws-muted">You do not currently hold a workstation. Choose a block below to browse rooms and pick a seat.</p>
                        </div>
                    </div>
                @endif
            </section>

            {{-- Locker Key. Its own panel regardless of which of the 4 states
                 applies. A CANCELLED row (the student's own cancel, or the
                 7-day auto-cancel) is treated exactly like no row at all --
                 it's history, not a status to show. --}}
            <section class="ws-panel" aria-labelledby="ws-key-label">
                <h2 class="ws-panel-label" id="ws-key-label">Locker Key</h2>

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
                    <p style="margin: 0;">
                        <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_RETURN_PENDING] }}">Return Pending</span>
                        @if ($returnPendingKey->isOverdueForReturn())
                            <span class="status-badge rejected">Overdue</span>
                        @endif
                    </p>
                    <p class="ws-muted">
                        Please return your locker key to the Centre for Graduate Studies (CGS) on or before
                        {{ $returnPendingKey->return_due_at->format('j M Y') }}.
                    </p>
                    <p class="ws-muted">Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00).</p>
                    {{-- PART 4: replaces the Request Key button a current seat
                         would otherwise show, and says why. --}}
                    <p style="font-weight: 600; margin: var(--space-3) 0 0;">Please return your previous locker key first.</p>
                @elseif (! $activeRequest)
                    <p class="ws-muted" style="margin: 0;">Select a workstation first to request your locker key.</p>
                @elseif ($lockerStatus === LockerKey::STATUS_COLLECTED)
                    <p style="margin: 0;">
                        <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_COLLECTED] }}">Collected</span>
                    </p>
                    <p class="ws-muted">Collected on {{ $lockerKey->collected_at->format('j M Y, g:ia') }}</p>
                @elseif ($lockerStatus === LockerKey::STATUS_REQUESTED)
                    <p style="margin: 0;">
                        <span class="status-badge {{ $lockerBadgeTone[LockerKey::STATUS_REQUESTED] }}">Pending Collection</span>
                    </p>
                    <p class="ws-muted">
                        Requested on {{ $lockerKey->requested_at->format('j M Y, g:ia') }}.
                        Please collect by {{ $lockerKey->collectByDate()->format('j M Y') }}.
                    </p>
                    <p class="ws-muted">{{ $lockerNote }}</p>

                    <form method="POST" action="{{ route('workstation.locker-key.cancel') }}"
                          class="js-confirm-form" data-confirm="Do you want to cancel your locker key request?">
                        @csrf
                        <button type="submit" class="btn-secondary">Cancel Request</button>
                    </form>
                @else
                    <p class="ws-muted" style="margin: 0;">{{ $lockerNote }}</p>

                    <form method="POST" action="{{ route('workstation.locker-key.request') }}"
                          class="js-confirm-form" data-confirm="Do you want to request the locker key for Seat {{ $activeRequest->workstation->seat_code }}?">
                        @csrf
                        <button type="submit">Request Key</button>
                    </form>
                @endif
            </section>
        </div>

        {{-- PART 4 #2. --}}
        <p class="ws-terms-line">
            By selecting a seat and pressing Confirm, you agree to the
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
        <h2 class="ws-section-title">Postgraduate Workstation Availability</h2>

        @if (! $gender)
            <div class="empty-state">
                Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).
            </div>
        @else
            <p class="ws-section-sub">Choose a block to see its rooms and open seats.</p>
            <div class="ws-card-grid">
                @foreach ($blocks as $block)
                    <a href="{{ route('workstation.rooms', $block->code) }}" class="ws-photo-card">
                        <img src="{{ $block->photoUrl() }}" alt="{{ $block->name }}" loading="lazy">
                        <div class="ws-photo-body">
                            <div class="ws-photo-head">
                                <span class="ws-photo-name">{{ $block->name }}</span>
                                <span class="ws-photo-cta" aria-hidden="true">View rooms &rarr;</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
