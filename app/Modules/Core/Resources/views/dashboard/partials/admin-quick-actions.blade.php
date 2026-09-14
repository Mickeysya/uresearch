{{--
    "Quick Shortcuts" for the administrator.

    Every tile points at a route that exists. The design's Add Student, Add
    Faculty, Create Course and Backup Now tiles are NOT here: there is no
    account-creation screen, no course model and no backup mechanism, so those
    four would be dead links. They are replaced by the destinations an
    administrator actually has — the same set the sidebar exposes.
--}}
@php
    $actions = [
        ['icon'=>'people',   'label'=>'Manage Users',   'sub'=>'Roles and permissions', 'route'=>'admin.users.index'],
        ['icon'=>'chart',    'label'=>'Generate Report','sub'=>'System analytics',      'route'=>'admin.reports.index'],
        ['icon'=>'settings', 'label'=>'System Settings','sub'=>'Global configuration',  'route'=>'settings.index'],
        ['icon'=>'search',   'label'=>'Audit Logs',     'sub'=>'Every system action',   'route'=>'admin.audit.index'],
        ['icon'=>'folder',   'label'=>'Applications',   'sub'=>'All modules',           'route'=>'admin.applications.index'],
        ['icon'=>'pulse',    'label'=>'Attendance',     'sub'=>'Institution-wide',      'route'=>'admin.attendance.overview'],
        ['icon'=>'doc',      'label'=>'Documents',      'sub'=>'Central repository',    'route'=>'admin.documents.index'],
        ['icon'=>'bell',     'label'=>'Notifications',  'sub'=>'Your alert feed',       'route'=>'notifications.index'],
    ];
@endphp

<section class="sdash-card">
    <header class="sdash-card-head">
        <h3>Quick Actions</h3>
    </header>

    <div class="sdash-card-body adm-actions">
        @foreach ($actions as $a)
            <a href="{{ route($a['route']) }}" class="adm-action">
                <span class="adm-action-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $a['icon']])
                </span>
                <span class="adm-action-text">
                    <span class="adm-action-label">{{ $a['label'] }}</span>
                    <span class="adm-action-sub">{{ $a['sub'] }}</span>
                </span>
            </a>
        @endforeach
    </div>
</section>
