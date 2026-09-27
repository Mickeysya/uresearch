@extends('core::layouts.app')

@section('title', 'Workstation')

@section('content')
@include('chloe::partials.styles')

@php
    $lockerBadgeTone = ['requested' => 'pending', 'collected' => 'approved', 'returned' => 'draft'];
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Workstation"
                         subtitle="Book a postgraduate workstation and request its locker key." />

    <div class="chloe-stack">
        <div class="card card-wide">
            <h3>My workstation</h3>

            @if ($activeRequest)
                <dl class="rpd-facts">
                    <div>
                        <dt>Seat</dt>
                        <dd>{{ $activeRequest->workstation->seat_code }}</dd>
                    </div>
                    <div>
                        <dt>Room</dt>
                        <dd>{{ $activeRequest->workstation->location->name }}</dd>
                    </div>
                    <div>
                        <dt>Block</dt>
                        <dd>{{ $activeRequest->workstation->location->block }}</dd>
                    </div>
                    <div>
                        <dt>Held since</dt>
                        <dd>{{ $activeRequest->requested_at->format('j M Y') }}</dd>
                    </div>
                    <div>
                        <dt>Locker key</dt>
                        <dd>
                            @if ($activeRequest->lockerKey)
                                <span class="status-badge {{ $lockerBadgeTone[$activeRequest->lockerKey->status] ?? 'draft' }}">{{ ucfirst($activeRequest->lockerKey->status) }}</span>
                            @else
                                Not requested
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="chloe-actions">
                    @unless ($activeRequest->lockerKey)
                        <form method="POST" action="{{ route('workstation.locker-key.request') }}">
                            @csrf
                            <button type="submit">Request locker key</button>
                        </form>
                    @endunless
                    <form method="POST" action="{{ route('workstation.release', $activeRequest) }}">
                        @csrf
                        <button type="submit" class="btn-reject">Release seat</button>
                    </form>
                </div>
            @else
                <div class="empty-state">
                    <p>You do not hold a workstation.</p>
                    <p class="queue-meta">Choose a block below to browse its rooms and pick a seat.</p>
                </div>
            @endif
        </div>

        <div class="card card-wide">
            <h3>Find a seat</h3>

            @if (! $gender)
                <p class="queue-meta">Rooms are designated by gender. Tell us yours to see the rooms that apply to you.</p>
                <form method="POST" action="{{ route('workstation.gender.set') }}" class="chloe-inline">
                    @csrf
                    <select name="gender" id="gender" aria-label="Gender" required>
                        <option value="">Select your gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                    <button type="submit">Continue</button>
                </form>
            @else
                <details class="queue-meta">
                    <summary>Showing rooms for <b>{{ ucfirst($gender) }}</b> students. Change</summary>
                    <form method="POST" action="{{ route('workstation.gender.set') }}" class="chloe-inline">
                        @csrf
                            <select name="gender" id="gender" aria-label="Gender" required>
                            <option value="male" @selected($gender === 'male')>Male</option>
                            <option value="female" @selected($gender === 'female')>Female</option>
                        </select>
                        <button type="submit" class="btn-secondary">Save</button>
                    </form>
                </details>

                <p class="chloe-eyebrow">Blocks</p>
                <div class="chloe-tiles">
                    @foreach ($blocks as $block)
                        <a href="{{ route('workstation.rooms', $block) }}" class="chloe-tile">
                            <span class="chloe-tile-title">Block {{ $block }}</span>
                            <span class="chloe-tile-meta">See its rooms</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
