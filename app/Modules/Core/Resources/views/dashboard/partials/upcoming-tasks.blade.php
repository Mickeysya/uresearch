{{--
    "Upcoming Tasks" — what is actually waiting on the student.

    These are DERIVED from real state, not read from a tasks table: this
    portal has no deadlines model yet, so rather than show invented due dates
    the panel lists the situations where the student genuinely has to act
    next. The rule lives in StudentDashboard::tasks() — change it there and
    this panel follows without edits.
--}}
<section class="sdash-card">
    @if (isset($unavailable['tasks']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 3])
    @endif

    <header class="sdash-card-head">
        <h3>Upcoming Tasks</h3>
        <a href="{{ route('calendar.index') }}" class="sdash-action">View Calendar</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($tasks as $task)
            <a href="{{ $task['url'] ?? route('applications.index') }}" class="sdash-row">
                <span class="sdash-row-icon tone-{{ $task['urgency'] }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'calendar'])
                </span>

                <span class="sdash-row-main">
                    <span class="sdash-row-title">{{ $task['title'] }}</span>
                    <span class="sdash-row-sub">{{ $task['subtitle'] }}</span>
                </span>

                <span class="sdash-row-side">
                    <span class="sdash-row-meta tone-{{ $task['urgency'] }}">{{ $task['meta'] }}</span>
                </span>

                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>
        @empty
            <p class="sdash-empty">Nothing needs your attention right now.</p>
        @endforelse
    </div>
</section>
