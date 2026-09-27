@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@php
    // Reusing the portal's own chart palette (dashboard-gauge.css /
    // charts.css tones) rather than the reference PDF's raw red/green, per
    // the brief: re-implement the structure, not the raw look.
    $seatColor = [
        'available' => '#00A857',
        'occupied' => '#D0342C',
        'reserved' => '#C7CEDB',
        'disabled' => '#E9B23C',
    ];
@endphp

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="{{ $workstationLocation->name }}">
        <a href="{{ route('workstation.select') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        <p style="margin:0;">
            <a href="{{ route('workstation.rooms', $workstationLocation->block) }}">&larr; Block {{ $workstationLocation->block }}</a>
        </p>

        <p style="color: var(--text-grey); font-size: 13px;">
            {{ $workstationLocation->room_code }} · Block {{ $workstationLocation->block }} ·
            <span class="status-badge {{ $workstationLocation->gender === 'female' ? 'rejected' : 'pending' }}">{{ $workstationLocation->genderLabel() }}</span>
            @if ($workstationLocation->description)
                · {{ $workstationLocation->description }}
            @endif
        </p>

        <div style="display:flex; gap:14px; align-items:center; font-size:13px; margin: 8px 0 16px;">
            <span><span style="display:inline-block;width:14px;height:14px;background:{{ $seatColor['available'] }};border-radius:3px;vertical-align:middle;"></span> Available</span>
            <span><span style="display:inline-block;width:14px;height:14px;background:{{ $seatColor['occupied'] }};border-radius:3px;vertical-align:middle;"></span> Not Available</span>
            <span><span style="display:inline-block;width:14px;height:14px;background:{{ $seatColor['disabled'] }};border-radius:3px;vertical-align:middle;"></span> Under Maintenance</span>
        </div>
        <div class="card-divider"></div>

        @if ($activeRequest && $activeRequest->workstation->workstation_location_id !== $workstationLocation->id)
            <div class="empty-state">
                You already hold Seat {{ $activeRequest->workstation->seat_code }} elsewhere. Release it from
                <a href="{{ route('workstation.select') }}">Home</a> before selecting a seat here.
            </div>
        @endif

        {{-- Whole-room overview: every seat in the room, in cluster/reading
             order, at a glance before the larger interactive view below. Not
             clickable — it's a map, not a second set of controls. --}}
        <p style="color: var(--text-grey); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px;">Room Overview</p>
        <div style="display:flex; flex-wrap:wrap; gap:14px; padding:14px; background: var(--border-grey, #eef1f6); border-radius:8px; margin-bottom: 24px;">
            @foreach ($seatsByCluster as $clusterLabel => $seats)
                <div style="display:flex; flex-wrap:wrap; gap:3px; align-content:flex-start;">
                    @foreach ($seats as $seat)
                        <div title="Seat {{ $seat->seat_code }}: {{ ucfirst($seat->status) }}"
                             style="width:22px;height:20px;border-radius:2px;color:#fff;font-size:9px;font-weight:600;display:flex;align-items:center;justify-content:center;background:{{ $seatColor[$seat->status] }};">
                            {{ $seat->seat_code }}
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <p style="color: var(--text-grey); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px;">Select a Seat</p>
        @foreach ($seatsByCluster as $clusterLabel => $seats)
            <div style="margin-bottom: 18px;">
                <p style="color: var(--text-grey); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px;">{{ $clusterLabel }}</p>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    @foreach ($seats as $seat)
                        @if ($seat->isAvailable() && ! $activeRequest)
                            <form method="POST" action="{{ route('workstation.register', $seat) }}">
                                @csrf
                                <button type="submit" title="Seat {{ $seat->seat_code }}: Available"
                                        style="width:48px;height:40px;padding:0;border:none;border-radius:4px;color:#fff;font-weight:600;cursor:pointer;background:{{ $seatColor['available'] }};display:flex;align-items:center;justify-content:center;">
                                    {{ $seat->seat_code }}
                                </button>
                            </form>
                        @else
                            <div title="Seat {{ $seat->seat_code }}: {{ ucfirst($seat->status) }}"
                                 style="width:48px;height:40px;border-radius:4px;color:#fff;font-weight:600;display:flex;align-items:center;justify-content:center;background:{{ $seatColor[$seat->status] }}; opacity:{{ $seat->isAvailable() ? '0.55' : '1' }};">
                                {{ $seat->seat_code }}
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="display:flex; gap:12px; margin-top: 8px;">
            <span class="status-badge draft">Entrance</span>
            <span class="status-badge draft">Entrance</span>
        </div>
    </div>
</div>
@endsection
