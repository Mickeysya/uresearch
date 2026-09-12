{{--
    The five figures across the top of the student dashboard.

    Each card is one entry in the $cards array below — add, remove or reorder
    them here and the grid re-flows on its own. Keys:

      tone   colour of the icon disc and the footer pill
             (green | purple | blue | orange | teal)
      value  the big number; pass null to render an em dash
      note   the grey line under the value
      pill   footer link text, or null for no footer
      route  where the pill goes, or null to render it as a plain label
--}}
@php
    use App\Modules\Core\Services\StudentDashboard;

    $cards = [
        [
            'tone' => 'green',
            'panel' => 'attendance',
            'icon' => 'pulse',
            'label' => 'Attendance',
            'value' => $attendance?->percentage !== null ? rtrim(rtrim(number_format((float) $attendance->percentage, 1), '0'), '.').'%' : null,
            'note' => 'Current Attendance',
            'pill' => $attendance
                ? ($attendance->at_risk ? 'Needs Attention' : 'Good Standing')
                : 'No data yet',
            'pillTone' => $attendance ? ($attendance->at_risk ? 'critical' : 'good') : 'muted',
            'route' => $attendance ? 'attendance.overview' : null,
        ],
        [
            'tone' => 'purple',
            'panel' => 'attendance',
            'icon' => 'trend',
            'label' => 'Predicted Attendance',
            'value' => $predicted !== null ? rtrim(rtrim(number_format($predicted, 1), '0'), '.').'%' : null,
            'note' => $predicted !== null ? 'Projected trend' : 'Not enough history',
            'pill' => $predicted !== null
                ? ($predicted >= StudentDashboard::attendanceBands()[0]['from'] ? 'On Track' : 'Trending Down')
                : null,
            'pillTone' => $predicted !== null && $predicted >= 85 ? 'info' : 'warn',
            'route' => $predicted !== null ? 'attendance.history' : null,
        ],
        [
            'tone' => 'blue',
            'panel' => 'applications',
            'icon' => 'folder',
            'label' => 'Active Applications',
            'value' => $activeCount,
            'note' => 'In Progress',
            'pill' => 'View All',
            'pillTone' => 'info',
            'route' => 'applications.index',
        ],
        [
            'tone' => 'orange',
            'panel' => 'notifications',
            'icon' => 'bell',
            'label' => 'Unread Notifications',
            'value' => $unreadCount,
            'note' => $unreadCount === 1 ? 'New' : 'New',
            'pill' => 'View Notifications',
            'pillTone' => 'warn',
            'route' => 'notifications.index',
        ],
        [
            'tone' => 'teal',
            'panel' => 'tasks',
            'icon' => 'calendar',
            'label' => 'Upcoming Tasks',
            'value' => $taskCount,
            'note' => $taskCount > 0 ? 'Need action' : 'Nothing due',
            'pill' => 'View Calendar',
            'pillTone' => 'info',
            'route' => 'calendar.index',
        ],
    ];
@endphp

<div class="sdash-stats">
    @foreach ($cards as $card)
        <div class="sdash-stat tone-{{ $card['tone'] }}">
            @if (isset($unavailable[$card['panel']]))
                @include('core::dashboard.partials.skeleton', ['type' => 'stat'])
            @endif

            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $card['icon']])
                </span>
                <span class="sdash-stat-label">{{ $card['label'] }}</span>
            </div>

            <div class="sdash-stat-value">{{ $card['value'] ?? '—' }}</div>
            <div class="sdash-stat-note">{{ $card['note'] }}</div>

            @if ($card['pill'])
                @if ($card['route'])
                    <a href="{{ route($card['route']) }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
                @else
                    <span class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</span>
                @endif
            @endif
        </div>
    @endforeach
</div>
