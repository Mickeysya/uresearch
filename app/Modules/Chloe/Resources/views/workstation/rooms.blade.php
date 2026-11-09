@extends('core::layouts.app')

@section('title', 'Workstation: Block ' . $block)

@section('content')
    @include('chloe::workstation._assistant-widget')

<link rel="stylesheet" href="{{ asset('css/chloe-workstation.css') }}">

<div class="card-container-inline">
    {{-- Back's target is identical to Home's here (this block's only
         parent is the landing page), so there is no history to prefer
         over it. --}}
    <x-core::page-header title="Block {{ $block }}" :back="route('workstation.select')" :back-history="false">
        <a href="{{ route('workstation.select') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    {{-- No width override here on purpose: layout.css already makes
         .card-container-inline > .card span the full .page-shell width, the
         same shell the page-header above sits in, so the panel's right edge
         lines up with the Home button's right edge for free. A max-width
         here previously fought that and left the panel short. --}}
    <div class="card card-wide">
        @if ($activeRequest)
            <div class="ws-notice" role="status">
                <span>
                    You already hold Seat {{ $activeRequest->workstation->seat_code }} in {{ $activeRequest->workstation->location->name }}.
                    Release it from the <a href="{{ route('workstation.select') }}">Home</a> screen before selecting another.
                </span>
            </div>
        @endif

        <h2 class="ws-section-title">Rooms in Block {{ $block }}</h2>
        <p class="ws-section-sub">Showing the rooms open to you. Choose one to pick a seat.</p>

        <div class="ws-card-grid">
            @foreach ($rooms as $room)
                @php
                    $total = max(1, (int) $room->workstations_count);
                    $free = (int) $room->available_count;
                    $percent = (int) round($free / $total * 100);
                    $tone = $free === 0 ? 'is-empty' : ($percent < 20 ? 'is-low' : '');
                @endphp
                <a href="{{ route('workstation.seats', $room) }}" class="ws-photo-card">
                    <img src="{{ $room->photoUrl() }}" alt="{{ $room->name }}" loading="lazy">
                    <div class="ws-photo-body">
                        <div class="ws-photo-head">
                            <span class="ws-photo-name">{{ $room->name }}</span>
                            <span class="ws-chip ws-gender-{{ $room->gender === 'female' ? 'female' : 'male' }}">{{ $room->genderLabel() }}</span>
                        </div>
                        <div class="ws-meter-label">
                            <span><b>{{ $room->available_count }}</b> of {{ $room->workstations_count }} seats available</span>
                        </div>
                        <svg class="ws-meter {{ $tone }}" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="ws-meter-track" width="100" height="6"></rect><rect class="ws-meter-fill" width="{{ $percent }}" height="6"></rect></svg>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endsection
