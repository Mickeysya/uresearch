@extends('core::layouts.guest')

@section('title', 'Confirm Workstation')

{{--
    Reached from the link in Email 1 -- deliberately on the guest layout, not
    the signed-in one: that layout's sidebar assumes an authenticated user
    (`auth()->user()->name` with no null-guard), and this page has to work
    for whoever opens the link, logged in or not. See
    WorkstationController::confirm().
--}}
@section('content')
    @include('chloe::workstation._assistant-widget')

<div class="card-container">
    <div class="card">
        @if ($outcome === 'confirmed')
            <h2>Workstation Confirmed</h2>
            <div class="card-divider"></div>
            <p>
                Seat <b>{{ $workstation->seat_code }}</b>, {{ $workstation->location->name }}
                (Block {{ $workstation->location->block }}) is now yours.
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                A confirmation email with these details has also been sent to you. Please refer to the seat
                layout displayed in the room to locate your seat.
            </p>
            <p style="color: var(--text-grey); font-size: 13px;">
                By using this workstation you agree to the
                <a href="{{ route('workstation.terms') }}">Postgraduate Workstation Term of Use</a>.
            </p>
        @elseif ($outcome === 'already-confirmed')
            <h2>Already Confirmed</h2>
            <div class="card-divider"></div>
            <p>
                This link has already been used. Seat <b>{{ $workstation->seat_code }}</b>,
                {{ $workstation->location->name }} (Block {{ $workstation->location->block }}) was confirmed
                earlier.
            </p>
        @elseif ($outcome === 'expired')
            <h2>Link Expired</h2>
            <div class="card-divider"></div>
            <p>This confirmation link has expired. Your selection has been cancelled. Please return to the dashboard and select a seat again.</p>
        @else
            <h2>Link Not Valid</h2>
            <div class="card-divider"></div>
            <p>
                This confirmation link is not valid. Please return to the dashboard and select a seat again.
            </p>
        @endif

        <a href="{{ route('workstation.select') }}"><button type="button">Go to Workstation</button></a>
    </div>
</div>
@endsection
