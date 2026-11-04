{{--
    "Start a new application": every module a student can self-submit to,
    from StudentDashboard::quickLinks() (ModuleRegistry::submittable()) --
    a teammate's new module appears here the moment they register it, with
    nothing in this file naming which modules exist.
--}}
@php
    $quickLinkIcons = [
        'ga_extension' => 'clock',
        'supervision' => 'people',
        'ga_certification' => 'certificate',
        'attendance_appeal' => 'pulse',
        'candidacy_appeal' => 'calendar',
        'rpd_appeal' => 'doc',
        'rpd_dismissal' => 'cross',
        're_viva' => 'calendar',
        'hardbound_submission' => 'doc',
        'hardbound_appeal' => 'doc',
        'appointment_letter' => 'doc',
        'examiner_nomination' => 'people',
        'travel' => 'plane',
        'publication' => 'book',
        'claims_student' => 'receipt',
    ];
@endphp

<section class="sdash-card">
    @if (isset($unavailable['quickLinks']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @endif

    <div class="sdash-card-head">
        <h3>Start a new application</h3>
        {{-- Only shown when a module supplies drafts (Contracts\SuppliesDraftCount) --
             no hardcoded route to a page that may have nothing for this student. --}}
        @if ($drafts)
            <a href="{{ $drafts['url'] }}" class="sdash-action">My Drafts{{ $drafts['count'] ? " ({$drafts['count']})" : '' }}</a>
        @endif
    </div>

    <div class="sdash-card-body">
        @if ($quickLinks->isEmpty())
            <p class="sdash-empty">No application types are available to submit yet.</p>
        @else
            <div class="sdash-quicklinks">
                @foreach ($quickLinks as $module)
                    <a href="{{ route($module->createRoute()) }}" class="sdash-quicklink">
                        <span class="sdash-quicklink-icon" aria-hidden="true">
                            @include('core::dashboard.partials.icon', ['name' => $quickLinkIcons[$module->key()] ?? 'doc'])
                        </span>
                        <span class="sdash-quicklink-label">{{ $module->label() }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>
