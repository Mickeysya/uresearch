@extends('core::layouts.app')

@section('title', 'Workstation')

@section('content')
@include('chloe::partials.styles')

@php
    $lockerBadgeTone = ['requested' => 'pending', 'collected' => 'approved', 'returned' => 'draft'];
    $rooms = $roomsByBlock->flatten();
    $freeSeats = $rooms->sum('available_count');
    $allSeats = $rooms->sum('workstations_count');
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Workstation">
        <x-slot:subtitle>
            @if ($gender)
                {{ $freeSeats }} of {{ $allSeats }} seats free across {{ $rooms->count() }} {{ Str::plural('room', $rooms->count()) }} for {{ $gender }} students.
            @else
                Book a postgraduate workstation and request its locker key.
            @endif
        </x-slot:subtitle>

        @if ($gender)
            <details class="chloe-setting">
                <summary class="btn-secondary">Rooms for: {{ ucfirst($gender) }}</summary>
                <form method="POST" action="{{ route('workstation.gender.set') }}" class="chloe-inline">
                    @csrf
                    <select name="gender" aria-label="Gender" required>
                        <option value="male" @selected($gender === 'male')>Male</option>
                        <option value="female" @selected($gender === 'female')>Female</option>
                    </select>
                    <button type="submit">Save</button>
                </form>
            </details>
        @endif
    </x-core::page-header>

    @if ($activeRequest)
        {{-- What they hold, first: it is the thing a returning student came
             to check or act on. --}}
        <section class="chloe-held" aria-label="Your workstation">
            <div class="chloe-held-seat">
                <span class="chloe-eyebrow">Your seat</span>
                <span class="chloe-held-code">{{ $activeRequest->workstation->seat_code }}</span>
            </div>
            <dl class="chloe-held-facts">
                <div><dt>Room</dt><dd>{{ $activeRequest->workstation->location->name }}</dd></div>
                <div><dt>Block</dt><dd>{{ $activeRequest->workstation->location->block }}</dd></div>
                <div><dt>Held since</dt><dd>{{ $activeRequest->requested_at->format('j M Y') }}</dd></div>
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
            <div class="chloe-held-actions">
                <a href="{{ route('workstation.seats', $activeRequest->workstation->workstation_location_id) }}" class="btn-secondary">View on map</a>
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
        </section>
    @endif

    @if (! $gender)
        {{-- Nothing else on this page means anything until we know which
             rooms apply, so this is the whole page. --}}
        <div class="card card-wide">
            <h3>Which rooms apply to you?</h3>
            <p class="queue-meta">Postgraduate rooms are designated by gender. You only need to tell us once; CGS can correct it later.</p>
            <form method="POST" action="{{ route('workstation.gender.set') }}" class="chloe-actions">
                @csrf
                <button type="submit" name="gender" value="male" class="btn-secondary">Male rooms</button>
                <button type="submit" name="gender" value="female" class="btn-secondary">Female rooms</button>
            </form>
        </div>
    @else
        @unless ($activeRequest)
            <p class="message-info">You do not hold a workstation yet. Pick a room below to see its seat map and book a seat.</p>
        @endunless

        @forelse ($roomsByBlock as $block => $blockRooms)
            <section class="chloe-block" aria-labelledby="block-{{ Str::slug($block) }}">
                <header class="chloe-block-head">
                    <h3 id="block-{{ Str::slug($block) }}">Block {{ $block }}</h3>
                    <span class="chloe-tile-meta">
                        {{ $blockRooms->sum('available_count') }} of {{ $blockRooms->sum('workstations_count') }} seats free
                        · {{ $blockRooms->count() }} {{ Str::plural('room', $blockRooms->count()) }}
                    </span>
                </header>

                <div class="chloe-tiles">
                    @foreach ($blockRooms as $room)
                        @php
                            $share = $room->workstations_count ? $room->available_count / $room->workstations_count : 0;
                            $tone = $room->available_count === 0 ? 'critical' : ($share < 0.25 ? 'warn' : 'good');
                        @endphp
                        <a href="{{ route('workstation.seats', $room) }}" class="chloe-tile">
                            <span class="chloe-tile-title">{{ $room->name }}</span>
                            @if ($room->room_code !== $room->name)
                                <span class="chloe-tile-meta">{{ $room->room_code }}</span>
                            @endif
                            <span class="chloe-meter tone-{{ $tone }}" role="img"
                                  aria-label="{{ $room->available_count }} of {{ $room->workstations_count }} seats free">
                                <span style="width: {{ round($share * 100) }}%"></span>
                            </span>
                            <span class="chloe-tile-foot">
                                <span class="chloe-tile-count tone-{{ $tone }}">
                                    {{ $room->available_count === 0 ? 'Full' : $room->available_count.' free' }}
                                </span>
                                <span class="chloe-tile-meta">of {{ $room->workstations_count }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="empty-state">
                <p>No rooms are designated for {{ $gender }} students yet.</p>
                <p class="queue-meta">CGS adds rooms to the catalogue. If you picked the wrong designation, change it above.</p>
            </div>
        @endforelse
    @endif
</div>
@endsection
