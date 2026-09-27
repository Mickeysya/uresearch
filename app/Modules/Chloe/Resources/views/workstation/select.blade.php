@extends('core::layouts.app')

@section('title', 'Workstation')

@php
    $lockerBadgeTone = ['requested' => 'pending', 'collected' => 'approved', 'returned' => 'draft'];
@endphp

@section('content')
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

            <form method="POST" action="{{ route('workstation.release', $activeRequest) }}" style="display:inline-block; margin-right: 8px;">
                @csrf
                <button type="submit">Release Seat</button>
            </form>

            @if ($activeRequest->lockerKey)
                <span class="status-badge {{ $lockerBadgeTone[$activeRequest->lockerKey->status] }}">
                    Locker Key: {{ ucfirst($activeRequest->lockerKey->status) }}
                </span>
            @else
                <form method="POST" action="{{ route('workstation.locker-key.request') }}" style="display:inline-block;">
                    @csrf
                    <button type="submit">Request Locker Key</button>
                </form>
            @endif
        @else
            <div class="empty-state">
                You do not currently hold a workstation. Choose a block below to browse rooms and pick a seat.
            </div>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h3>Postgraduate Workstation Availability</h3>
        <div class="card-divider"></div>

        @if (! $gender)
            <p>Rooms are designated by gender; tell us yours to see the rooms that apply to you.</p>
            <form method="POST" action="{{ route('workstation.gender.set') }}">
                @csrf
                <select name="gender" required>
                    <option value="">Select&hellip;</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
                <button type="submit">Continue</button>
            </form>
        @else
            <p style="color: var(--text-grey); font-size: 13px; margin-bottom: 4px;">
                Showing rooms for: <b>{{ ucfirst($gender) }}</b>
            </p>
            <details style="margin-bottom:12px;">
                <summary style="cursor:pointer; color: var(--text-grey); font-size: 13px;">Change</summary>
                <form method="POST" action="{{ route('workstation.gender.set') }}" style="margin-top:8px;">
                    @csrf
                    <select name="gender" required>
                        <option value="male" @selected($gender === 'male')>Male</option>
                        <option value="female" @selected($gender === 'female')>Female</option>
                    </select>
                    <button type="submit">Save</button>
                </form>
            </details>

            <p style="color: var(--text-grey); font-size: 13px;">Click a block to see its rooms.</p>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                @foreach ($blocks as $block)
                    <a href="{{ route('workstation.rooms', $block) }}" style="text-decoration:none;">
                        <button type="button" style="min-width: 160px; padding: 18px 28px; font-size: 15px;">Block {{ $block }}</button>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
