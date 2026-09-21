@extends('core::layouts.app')

@section('title', 'Block ' . $block . ' — Workstation')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <p><a href="{{ route('workstation.select') }}">&larr; Home</a></p>
        <h2>Block {{ $block }}</h2>
        <p style="color: var(--text-grey); font-size: 13px;">
            Each room is designated for one gender — pick the room that applies to you.
        </p>
        <div class="card-divider"></div>

        @if ($activeRequest)
            <div class="empty-state">
                You already hold Seat {{ $activeRequest->workstation->seat_code }} in {{ $activeRequest->workstation->location->name }}.
                Release it from the <a href="{{ route('workstation.select') }}">Home</a> screen before selecting another.
            </div>
        @endif

        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin-top: 12px;">
            @foreach ($rooms as $room)
                <a href="{{ route('workstation.seats', $room) }}" class="card" style="margin:0; padding:16px; text-decoration:none; color:inherit;">
                    <b>{{ $room->name }}</b>
                    <div style="margin-top:6px;">
                        <span class="status-badge {{ $room->gender === 'female' ? 'rejected' : 'pending' }}">{{ $room->genderLabel() }}</span>
                    </div>
                    <p style="color: var(--text-grey); font-size: 13px; margin-top:6px;">
                        {{ $room->available_count }} of {{ $room->workstations_count }} seats available
                    </p>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endsection
