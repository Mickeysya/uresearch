{{--
    "Recent Activities" — institution-wide.

    Three sources merged in AdminDashboard::recentActivities(): a new account
    in `users`, a submission in `applications`, and a decision in
    `approval_history`. Merged rather than kept in an activity table nobody
    would remember to write to.
--}}
<section class="sdash-card">
    @if (isset($unavailable['activity']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 5])
    @endif

    <header class="sdash-card-head">
        <h3>Recent Activities</h3>
        <a href="{{ route('admin.audit.index') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($activities as $a)
            <div class="sdash-row adm-activity">
                <span class="sdash-row-icon tone-{{ $a['tone'] }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $a['icon']])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">{{ $a['text'] }}</span>
                    <span class="sdash-row-sub">{{ $a['sub'] }}</span>
                </span>
                <span class="sdash-row-meta">{{ $a['at']?->diffForHumans(null, true) }} ago</span>
            </div>
        @empty
            <p class="sdash-empty">Nothing has happened yet.</p>
        @endforelse
    </div>
</section>
