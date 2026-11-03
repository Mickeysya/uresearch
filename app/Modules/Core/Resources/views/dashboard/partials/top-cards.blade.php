{{--
    The four cards across the top of the student dashboard: Today,
    Study candidacy, Viva, GA allowance.

    Candidacy, Viva and Allowance each come from an optional Core contract
    (SuppliesCandidacyStatus / SuppliesVivaStatus / SuppliesAllowanceStatus)
    -- a module binds one in its own ModuleProvider, same seam as
    SuppliesAttendance. Viva and Allowance have no implementer yet, so those
    two always render their honest "not available" state; nothing here
    invents a date or a figure. See Services\StudentDashboard.
--}}
@php use Illuminate\Support\Str; @endphp

<div class="sdash-top">

    {{-- Today --}}
    <div class="sdash-top-card sdash-today">
        <div class="sdash-today-head">
            <span class="sdash-today-dow">{{ strtoupper(now()->format('l')) }}</span>
            <span class="sdash-today-month">{{ now()->format('M Y') }}</span>
        </div>
        <div class="sdash-today-date">{{ now()->format('j') }}</div>

        <div class="sdash-mini-week" aria-hidden="true">
            @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $label)
                <span class="sdash-mini-week-label">{{ $label }}</span>
            @endforeach
        </div>
        <div class="sdash-mini-week">
            @foreach ($today['week'] as $day)
                <span class="sdash-mini-day {{ $day->isToday() ? 'is-today' : '' }}">{{ $day->format('j') }}</span>
            @endforeach
        </div>

        <div class="sdash-today-events">
            @forelse ($today['today'] as $event)
                <div class="sdash-today-event tone-{{ $event['tone'] ?? 'info' }}">
                    <span class="sdash-today-event-title">{{ $event['title'] }}</span>
                    @if (! empty($event['meta']))
                        <span class="sdash-today-event-meta">{{ $event['meta'] }}</span>
                    @endif
                </div>
            @empty
                <p class="sdash-today-empty">No events today.</p>
            @endforelse
        </div>

        <a href="{{ route('calendar.index') }}" class="sdash-today-footer">
            @if ($today['tomorrowCount'] > 0)
                Tomorrow: {{ $today['tomorrowCount'] }} {{ Str::plural('event', $today['tomorrowCount']) }} · Open calendar
            @else
                Open calendar
            @endif
        </a>
    </div>

    {{-- Study candidacy --}}
    <div class="sdash-top-card">
        <div class="sdash-top-head">
            <span class="sdash-top-icon-wrap tone-purple">@include('core::dashboard.partials.icon', ['name' => 'calendar'])</span>
            <span class="sdash-top-title">Study candidacy</span>
            @if ($candidacy)
                <span class="sdash-pill pill-{{ $candidacy['tone'] }} sdash-top-badge">{{ $candidacy['statusLabel'] }}</span>
            @endif
        </div>

        @if ($candidacy)
            @php
                $days = $candidacy['daysRemaining'];
                $elapsed = $candidacy['startedAt']?->diffInDays(now());
                $span = $candidacy['startedAt']?->diffInDays($candidacy['expiresAt']);
                $percent = $span ? min(100, max(0, round($elapsed / $span * 100))) : 0;
            @endphp
            <div class="sdash-top-value">{{ max(0, $days) }} <span class="sdash-top-unit">{{ $days < 0 ? 'days overdue' : 'days remaining' }}</span></div>

            <div class="sdash-top-progress">
                <div class="sdash-top-progress-bar tone-{{ $candidacy['tone'] }}" style="width: {{ $percent }}%"></div>
            </div>
            <div class="sdash-top-progress-labels">
                <span>{{ $candidacy['startedAt']?->format('j M Y') ?? '—' }}</span>
                <span>Expires {{ $candidacy['expiresAt']->format('j M Y') }}</span>
            </div>

            <div class="sdash-top-fact">Extension used: <strong>{{ $candidacy['extensionUsedMonths'] }} of {{ $candidacy['extensionMaxMonths'] }} months</strong></div>

            <a href="{{ $candidacy['url'] }}" class="sdash-top-action">View candidacy</a>
        @else
            <div class="sdash-top-empty">
                <span class="sdash-top-empty-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'calendar'])</span>
                <p>No candidacy is on record for you yet. CGS registers this once your programme starts.</p>
            </div>
        @endif
    </div>

    {{-- Viva --}}
    <div class="sdash-top-card">
        <div class="sdash-top-head">
            <span class="sdash-top-icon-wrap tone-blue">@include('core::dashboard.partials.icon', ['name' => 'calendar'])</span>
            <span class="sdash-top-title">Viva</span>
            @if ($viva)
                <span class="sdash-pill pill-info sdash-top-badge">Scheduled</span>
            @endif
        </div>

        @if ($viva)
            <div class="sdash-top-value">{{ max(0, $viva['daysRemaining']) }} <span class="sdash-top-unit">days to viva</span></div>
            <div class="sdash-top-fact">{{ $viva['date']->format('D j M') }}@if ($viva['time']) · {{ $viva['time'] }} @endif</div>
            <a href="{{ $viva['url'] }}" class="sdash-top-action">View viva details</a>
        @else
            <div class="sdash-top-empty">
                <span class="sdash-top-empty-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'calendar'])</span>
                <p>Not scheduled yet. It will appear here once CGS confirms a date.</p>
            </div>
        @endif
    </div>

    {{-- GA allowance --}}
    <div class="sdash-top-card">
        <div class="sdash-top-head">
            <span class="sdash-top-icon-wrap tone-green">@include('core::dashboard.partials.icon', ['name' => 'coin'])</span>
            <span class="sdash-top-title">GA allowance</span>
            @if ($allowance)
                <span class="sdash-pill pill-good sdash-top-badge">{{ $allowance['statusLabel'] }}</span>
            @endif
        </div>

        @if ($allowance)
            <div class="sdash-top-value">RM {{ number_format($allowance['amount']) }} <span class="sdash-top-unit">/ month</span></div>
            <div class="sdash-top-stat-grid">
                <div class="sdash-top-stat-cell">
                    <span class="sdash-top-fact-label">Last paid</span>
                    <span class="sdash-top-fact-value">{{ $allowance['lastPaidAt']?->format('j M Y') ?? '—' }}</span>
                </div>
                <div class="sdash-top-stat-cell">
                    <span class="sdash-top-fact-label">Next payment</span>
                    <span class="sdash-top-fact-value">{{ $allowance['nextPaymentAt']?->format('j M Y') ?? '—' }}</span>
                </div>
            </div>
            @if ($allowance['note'])
                <div class="sdash-top-note">{{ $allowance['note'] }}</div>
            @endif
            <a href="{{ $allowance['url'] }}" class="sdash-top-action">Payment history</a>
        @else
            <div class="sdash-top-empty">
                <span class="sdash-top-empty-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'coin'])</span>
                <p>Not on a GA/GRA allowance, or payment tracking is not available yet.</p>
            </div>
        @endif
    </div>

</div>
