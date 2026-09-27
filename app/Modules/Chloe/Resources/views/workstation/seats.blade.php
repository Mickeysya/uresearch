@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@section('content')
@php use App\Modules\Chloe\Models\Workstation; @endphp
@include('chloe::partials.styles')

@php
    $heldElsewhere = $activeRequest && $activeRequest->workstation->workstation_location_id !== $workstationLocation->id;
@endphp

<div class="card-container-inline">
    <x-core::page-header :title="$workstationLocation->name">
        <x-slot:subtitle>
            {{ $workstationLocation->room_code }} · Block {{ $workstationLocation->block }} · {{ $workstationLocation->genderLabel() }}
            @if ($workstationLocation->description)
                · {{ $workstationLocation->description }}
            @endif
        </x-slot:subtitle>

        <a href="{{ route('workstation.rooms', $workstationLocation->block) }}" class="btn-secondary">Block {{ $workstationLocation->block }}</a>
    </x-core::page-header>

    @if ($heldElsewhere)
        <p class="message-info">
            You already hold Seat {{ $activeRequest->workstation->seat_code }} elsewhere. Release it from
            <a href="{{ route('workstation.select') }}">your workstation</a> before choosing a seat here.
        </p>
    @elseif ($activeRequest)
        <p class="message-info">You hold Seat {{ $activeRequest->workstation->seat_code }} in this room.</p>
    @endif

    <div class="card card-wide">
        <div class="seat-legend">
            <span><span class="seat is-available"></span> Available</span>
            <span><span class="seat is-occupied"></span> Not available</span>
            <span><span class="seat is-reserved"></span> Reserved</span>
            <span><span class="seat is-disabled"></span> Under maintenance</span>
        </div>

        {{-- Whole-room overview: every seat in cluster order, at a glance
             before the larger interactive view below. Not clickable; it is a
             map, not a second set of controls. --}}
        <p class="chloe-eyebrow">Room overview</p>
        <div class="seat-map" aria-hidden="true">
            @foreach ($seatsByCluster as $clusterLabel => $seats)
                <div class="seat-cluster">
                    @foreach ($seats as $seat)
                        <span class="seat is-{{ $seat->status }}" title="Seat {{ $seat->seat_code }}: {{ Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}">{{ $seat->seat_code }}</span>
                    @endforeach
                </div>
            @endforeach
        </div>

        @foreach ($seatsByCluster as $clusterLabel => $seats)
            <p class="chloe-eyebrow">{{ $clusterLabel ?: 'Seats' }}</p>
            <div class="seat-cluster">
                @foreach ($seats as $seat)
                    @php($label = 'Seat '.$seat->seat_code.': '.(Workstation::statuses()[$seat->status] ?? ucfirst($seat->status)))
                    @if ($seat->isAvailable() && ! $activeRequest)
                        <form method="POST" action="{{ route('workstation.register', $seat) }}">
                            @csrf
                            <button type="submit" class="seat is-available" title="{{ $label }}. Select it" aria-label="Book seat {{ $seat->seat_code }}">{{ $seat->seat_code }}</button>
                        </form>
                    @else
                        <span class="seat is-{{ $seat->status }} @if ($seat->isAvailable()) is-muted @endif" title="{{ $label }}">{{ $seat->seat_code }}</span>
                    @endif
                @endforeach
            </div>
        @endforeach
    </div>
</div>
@endsection
