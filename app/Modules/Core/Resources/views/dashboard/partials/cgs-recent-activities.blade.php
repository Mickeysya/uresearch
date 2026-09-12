{{--
    "Recent Activities" — one merged feed of submissions and decisions.

    The portal records those separately (a row in `applications` versus a row
    in `approval_history`), so CgsDashboard::recentActivities() merges and
    re-sorts them rather than keeping a third table nobody would remember to
    write to.
--}}
<section class="sdash-card">
    @if (isset($unavailable['activity']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @endif

    <header class="sdash-card-head">
        <h3>Recent Activities</h3>
        <a href="{{ route('notifications.index') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($activities as $activity)
            <div class="cgs-activity">
                <span class="cgs-activity-icon tone-{{ $activity['tone'] }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $activity['icon']])
                </span>
                <span class="cgs-activity-text">{{ $activity['text'] }}</span>
                <span class="cgs-activity-time">{{ $activity['at']?->diffForHumans(null, true) }} ago</span>
            </div>
        @empty
            <p class="sdash-empty">Nothing has happened yet.</p>
        @endforelse
    </div>
</section>
