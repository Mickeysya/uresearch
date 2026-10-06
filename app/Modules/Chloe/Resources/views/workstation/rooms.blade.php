@extends('core::layouts.app')

@section('title', 'Workstation: Block ' . $block)

@section('content')
@include('chloe::partials.styles')

<div class="card-container-inline">
    <x-core::page-header :title="'Block '.$block"
                         subtitle="Each room is designated for one gender. Pick a room to see its seats.">
        <a href="{{ route('workstation.select') }}" class="btn-secondary">All blocks</a>
    </x-core::page-header>

    @if ($activeRequest)
        <p class="message-info">
            You already hold Seat {{ $activeRequest->workstation->seat_code }} in {{ $activeRequest->workstation->location->name }}.
            Release it from <a href="{{ route('workstation.select') }}">your workstation</a> before choosing another.
        </p>
    @endif

    <div class="card card-wide">
        @if ($rooms->isEmpty())
            <div class="empty-state">No rooms in this block are open to you.</div>
        @else
            <div class="chloe-tiles">
                @foreach ($rooms as $room)
                    <a href="{{ route('workstation.seats', $room) }}" class="chloe-tile">
                        <span class="chloe-tile-title">{{ $room->name }}</span>
                        @if ($room->room_code !== $room->name)
                            <span class="chloe-tile-meta">{{ $room->room_code }}</span>
                        @endif
                        <span><span class="status-badge draft">{{ $room->genderLabel() }}</span></span>
                        <span class="chloe-tile-meta">{{ $room->available_count }} of {{ $room->workstations_count }} seats available</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
