{{--
    "Quick Shortcuts" — the eight places CGS goes most often.

    Every tile points at a route that exists. Where the design named a
    feature the portal does not have yet, the tile goes to that feature's
    placeholder page rather than a dead link, so the grid is honest about
    what is behind it.

    Edit the $shortcuts array to add or reorder tiles; the grid reflows.
--}}
@php
    $shortcuts = [
        ['icon' => 'search',      'label' => 'Application Search', 'route' => 'applications.index'],
        ['icon' => 'pulse',       'label' => 'Student Attendance', 'route' => 'cgs.attendance.students'],
        ['icon' => 'chart',       'label' => 'Generate Reports',   'route' => 'cgs.reports.index'],
        ['icon' => 'upload',      'label' => 'Upload Attendance',  'route' => 'attendance.upload.form'],
        ['icon' => 'folder',      'label' => 'Application Tracker','route' => 'cgs.reports.index'],
        ['icon' => 'bell',        'label' => 'Notification Centre','route' => 'notifications.index'],
        ['icon' => 'calendar',    'label' => 'Calendar View',      'route' => 'calendar.index'],
        ['icon' => 'settings',    'label' => 'System Settings',    'route' => 'settings.index'],
    ];
@endphp

<section class="sdash-card">
    <header class="sdash-card-head">
        <h3>Quick Shortcuts</h3>
    </header>

    <div class="sdash-card-body cgs-shortcuts">
        @foreach ($shortcuts as $item)
            <a href="{{ route($item['route']) }}" class="cgs-shortcut">
                <span class="cgs-shortcut-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $item['icon']])
                </span>
                <span class="cgs-shortcut-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
</section>
