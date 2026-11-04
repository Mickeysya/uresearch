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

@php
    // Pages of eight: a 4 x 2 grid of tiles, the arrows step a page at a time.
    $quickLinkPages = $quickLinks->chunk(8);
@endphp

<section class="sdash-card" data-ql>
    @if (isset($unavailable['quickLinks']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @endif

    <div class="sdash-card-head">
        <h3>Start a new application</h3>
        <div class="sdash-ql-head-actions">
            {{-- Only shown when a module supplies drafts (Contracts\SuppliesDraftCount) --
                 no hardcoded route to a page that may have nothing for this student. --}}
            @if ($drafts)
                <a href="{{ $drafts['url'] }}" class="sdash-action">My Drafts{{ $drafts['count'] ? " ({$drafts['count']})" : '' }}</a>
            @endif
            @if ($quickLinkPages->count() > 1)
                <div class="sdash-ql-nav">
                    <button type="button" class="sdash-ql-arrow is-prev" data-ql-prev aria-label="Previous application types" disabled>
                        @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                    </button>
                    <span class="sdash-ql-count" data-ql-count aria-live="polite">1 / {{ $quickLinkPages->count() }}</span>
                    <button type="button" class="sdash-ql-arrow" data-ql-next aria-label="Next application types">
                        @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div class="sdash-card-body">
        @if ($quickLinks->isEmpty())
            <p class="sdash-empty">No application types are available to submit yet.</p>
        @else
            {{-- A horizontal scroll-snap track, one page wide. The arrows
                 just scroll it; without JavaScript it still swipes. --}}
            <div class="sdash-ql-track" data-ql-track>
                @foreach ($quickLinkPages as $page)
                    <div class="sdash-ql-page">
                        @foreach ($page as $module)
                            <a href="{{ route($module->createRoute()) }}" class="sdash-ql-tile">
                                <span class="sdash-ql-icon" aria-hidden="true">
                                    @include('core::dashboard.partials.icon', ['name' => $quickLinkIcons[$module->key()] ?? 'doc'])
                                </span>
                                <span class="sdash-ql-label">{{ $module->label() }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

@if ($quickLinkPages->count() > 1)
    @push('scripts')
        <script @cspNonce>
        (function () {
            var root = document.querySelector('[data-ql]');
            if (! root) return;
            var track = root.querySelector('[data-ql-track]');
            var prev = root.querySelector('[data-ql-prev]');
            var next = root.querySelector('[data-ql-next]');
            var count = root.querySelector('[data-ql-count]');
            var pages = track.children.length;

            function current() { return Math.round(track.scrollLeft / track.clientWidth); }

            function sync() {
                var i = current();
                prev.disabled = i <= 0;
                next.disabled = i >= pages - 1;
                count.textContent = (i + 1) + ' / ' + pages;
            }

            function go(step) {
                track.scrollTo({ left: (current() + step) * track.clientWidth, behavior: 'smooth' });
            }

            prev.addEventListener('click', function () { go(-1); });
            next.addEventListener('click', function () { go(1); });
            track.addEventListener('scroll', function () { window.requestAnimationFrame(sync); }, { passive: true });
            window.addEventListener('resize', sync);
            sync();
        })();
        </script>
    @endpush
@endif
