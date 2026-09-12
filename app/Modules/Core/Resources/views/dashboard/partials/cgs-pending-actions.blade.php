{{--
    "Pending Actions" — the newest applications sitting on this role's own
    stages. Module label and route both come from the registry, so nothing
    here names a specific module.
--}}
@php
    $moduleIcons = [
        'ga_extension' => 'doc', 'supervision' => 'person', 'ga_certification' => 'certificate',
        'attendance_appeal' => 'pencil', 'travel' => 'folder', 'examiner_nomination' => 'person',
    ];
    $moduleTones = [
        'ga_extension' => 'info', 'supervision' => 'info', 'ga_certification' => 'good',
        'attendance_appeal' => 'critical', 'travel' => 'warn', 'examiner_nomination' => 'info',
    ];
@endphp

<section class="sdash-card">
    @if (isset($unavailable['applications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @endif

    <header class="sdash-card-head">
        <h3>Pending Actions</h3>
        <a href="{{ route('applications.index') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($pendingActions as $application)
            @php
                $module = $application->module();
                $tone = $moduleTones[$application->module_type] ?? 'info';
            @endphp
            <a href="{{ route($module->queueRoute(), ['stage' => $application->current_stage]) }}" class="sdash-row">
                <span class="sdash-row-icon tone-{{ $tone }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $moduleIcons[$application->module_type] ?? 'doc'])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">{{ $module->label() }}</span>
                    <span class="sdash-row-sub">{{ $application->student->name }}</span>
                </span>
                <span class="sdash-row-side">
                    <span class="sdash-pill pill-{{ $tone }}">{{ Str::limit($module->label(), 14, '') }}</span>
                    <span class="sdash-row-meta">{{ $application->submitted_at?->diffForHumans(null, true) }} ago</span>
                </span>
                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>
        @empty
            <p class="sdash-empty">Nothing is waiting on you right now.</p>
        @endforelse
    </div>
</section>
