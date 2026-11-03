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
      url    optional: a ready-made link for the pill, used instead of route
      textValue  optional: true when value is words rather than a number
--}}
@php
    use App\Modules\Core\Services\StudentDashboard;

    $cards = [
        [
            'tone' => 'green',
            'panel' => 'attendance',
            'icon' => 'pulse',
            'label' => 'Attendance',
            // Starts full, like the gauge below it -- StudentDashboard::STARTING_PERCENTAGE.
            // The pill still says nothing has been uploaded, so the figure is
            // not mistaken for a measured one.
            'value' => rtrim(rtrim(number_format(
                $attendance !== null ? (float) $attendance->percentage : StudentDashboard::STARTING_PERCENTAGE, 1
            ), '0'), '.').'%',
            'note' => 'Current Attendance',
            'pill' => $attendance
                ? ($attendance->at_risk ? 'Needs Attention' : 'Good Standing')
                : 'No data yet',
            'pillTone' => $attendance ? ($attendance->at_risk ? 'critical' : 'good') : 'muted',
            'route' => $attendance ? 'attendance.overview' : null,
        ],
        // What to do next about attendance, worded by the attendance module
        // (SuppliesAttendance::actionFor()). Never a projected figure.
        [
            'tone' => 'purple',
            'panel' => 'attendance',
            'icon' => 'trend',
            'label' => $attendanceAction['label'] ?? 'Attendance Target',
            'value' => $attendanceAction['value'] ?? null,
            'note' => $attendanceAction['note'] ?? 'Minimum 80% this semester',
            'pill' => $attendanceAction['pill'] ?? null,
            'pillTone' => $attendanceAction['pillTone'] ?? 'info',
            'url' => $attendanceAction['url'] ?? null,
            'route' => null,
            // A sentence, not a figure: set smaller so it fits the card.
            'textValue' => true,
        ],
        [
            'tone' => 'blue',
            'panel' => 'applications',
            'icon' => 'folder',
            'label' => 'Active Applications',
            'value' => $activeCount,
            'note' => $nextDecision ? 'In progress · next decision by '.$nextDecision->format('j M') : 'In progress',
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

            <div class="sdash-stat-value" @if ($card['textValue'] ?? false) style="font-size: clamp(15px, 1.3vw, 19px); line-height: 1.25;" @endif>{{ $card['value'] ?? '—' }}</div>
            <div class="sdash-stat-note">{{ $card['note'] }}</div>

            @if ($card['pill'])
                @if (($card['url'] ?? null) || $card['route'])
                    <a href="{{ $card['url'] ?? route($card['route']) }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
                @else
                    <span class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</span>
                @endif
            @endif
        </div>
    @endforeach
</div>
