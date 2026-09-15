@extends('core::layouts.app')

@section('title', 'Calendar — ' . $month->format('F Y'))

@section('content')
<div class="rpd-page">
    <header class="rpd-header">
        <div>
            <h2>Calendar</h2>
            <p class="queue-meta">
                Deadlines and dates from your applications. Nothing is entered here —
                every date comes from a record that already exists.
            </p>
        </div>

        <nav class="cal-nav" aria-label="Change month">
            <a href="{{ route('calendar.index', ['month' => $prevMonth]) }}"
               class="cal-nav-btn" aria-label="Previous month" rel="prev">&lsaquo;</a>
            <span class="cal-nav-label">{{ $month->format('F Y') }}</span>
            <a href="{{ route('calendar.index', ['month' => $nextMonth]) }}"
               class="cal-nav-btn" aria-label="Next month" rel="next">&rsaquo;</a>
            @unless ($month->isSameMonth($today))
                <a href="{{ route('calendar.index') }}" class="sdash-action">Today</a>
            @endunless
        </nav>
    </header>

    <div class="cal-layout">
        <section class="cal-grid-wrap" aria-label="{{ $month->format('F Y') }}">
            <div class="cal-weekdays" aria-hidden="true">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                    <span>{{ $day }}</span>
                @endforeach
            </div>

            <div class="cal-grid">
                @php($cursor = $gridStart->copy())
                @while ($cursor <= $gridEnd)
                    @php($key = $cursor->toDateString())
                    @php($dayEvents = $events[$key] ?? collect())

                    <div class="cal-day
                                @if ($cursor->month !== $month->month) is-outside @endif
                                @if ($cursor->isSameDay($today)) is-today @endif
                                @if ($dayEvents->isNotEmpty()) has-events @endif">
                        <span class="cal-daynum">{{ $cursor->day }}</span>

                        @foreach ($dayEvents as $event)
                            @php($tone = $event['tone'] ?? 'info')
                            @if (($event['url'] ?? null))
                                <a href="{{ $event['url'] }}" class="cal-event tone-{{ $tone }}"
                                   title="{{ $event['title'] }}@if(!empty($event['meta'])) — {{ $event['meta'] }}@endif">
                                    {{ $event['title'] }}
                                </a>
                            @else
                                <span class="cal-event tone-{{ $tone }}"
                                      title="{{ $event['title'] }}">{{ $event['title'] }}</span>
                            @endif
                        @endforeach
                    </div>

                    @php($cursor->addDay())
                @endwhile
            </div>
        </section>

        {{-- A month grid alone never answers "what is next" — open it in March
             and December's deadline is invisible. This looks a year ahead
             regardless of which month is on screen. --}}
        <aside class="cal-upcoming" aria-label="Upcoming dates">
            <h3>Coming up</h3>

            @forelse ($upcoming as $event)
                @php($date = \Carbon\Carbon::parse($event['date']))
                @php($days = (int) now()->startOfDay()->diffInDays($date, false))

                <a class="cal-up-row tone-{{ $event['tone'] ?? 'info' }}"
                   @if (!empty($event['url'])) href="{{ $event['url'] }}" @endif>
                    <span class="cal-up-date">
                        <b>{{ $date->format('j') }}</b>
                        <span>{{ $date->format('M') }}</span>
                    </span>
                    <span class="cal-up-main">
                        <span class="cal-up-title">{{ $event['title'] }}</span>
                        <span class="cal-up-meta">
                            {{ $days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : "in {$days} days") }}
                            @if (!empty($event['meta'])) &middot; {{ $event['meta'] }} @endif
                        </span>
                    </span>
                </a>
            @empty
                <div class="empty-state">
                    <p>Nothing scheduled.</p>
                    <p class="queue-meta">Deadlines appear here as soon as you have an
                       application or a candidacy with a date on it.</p>
                </div>
            @endforelse
        </aside>
    </div>
</div>
@endsection
