{{--
    "My Application Status" — the student's most recent submissions.

    Rows come straight from the workflow engine: the module supplies its own
    label and the status badge reads applications.status, so a teammate's new
    module appears here the moment a student submits one. Nothing in this
    file names a specific module.
--}}
@php
    // Which icon goes with which module. A module not listed falls back to
    // the generic document icon, so this never needs editing to stay working.
    $moduleIcons = [
        'ga_extension' => 'doc',
        'supervision' => 'person',
        'ga_certification' => 'certificate',
        'attendance_appeal' => 'pencil',
        'travel' => 'folder',
        'examiner_nomination' => 'person',
    ];
@endphp

<section class="sdash-card">
    @if (isset($unavailable['applications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @endif

    <header class="sdash-card-head">
        <h3>My Application Status</h3>
        <a href="{{ route('applications.index') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($applications as $application)
            @php
                $decision = $application->history->last();
                $tone = match ($application->status) {
                    \App\Modules\Core\Models\Application::STATUS_APPROVED => 'good',
                    \App\Modules\Core\Models\Application::STATUS_REJECTED => 'critical',
                    default => $decision ? 'info' : 'warn',
                };
                $statusLabel = match ($application->status) {
                    \App\Modules\Core\Models\Application::STATUS_APPROVED => 'Approved',
                    \App\Modules\Core\Models\Application::STATUS_REJECTED => 'Rejected',
                    default => $decision ? 'Under Review' : 'Pending Approval',
                };
            @endphp

            <a href="{{ route('applications.show', $application) }}" class="sdash-row">
                <span class="sdash-row-icon tone-{{ $tone }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $moduleIcons[$application->module_type] ?? 'doc'])
                </span>

                <span class="sdash-row-main">
                    <span class="sdash-row-title">{{ $application->module()->label() }}</span>
                    <span class="sdash-row-sub">Reference: {{ $application->reference() }}</span>
                </span>

                <span class="sdash-row-side">
                    <span class="sdash-pill pill-{{ $tone }}">{{ $statusLabel }}</span>
                    <span class="sdash-row-meta">
                        @if ($application->status === \App\Modules\Core\Models\Application::STATUS_PENDING)
                            Submitted on {{ $application->submitted_at?->format('j M Y') ?? '—' }}
                        @else
                            {{ $statusLabel }} on {{ $decision?->created_at->format('j M Y') ?? '—' }}
                        @endif
                    </span>
                </span>

                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>
        @empty
            <p class="sdash-empty">No applications yet. Pick one from <b>My Application</b> in the sidebar to get started.</p>
        @endforelse
    </div>
</section>
