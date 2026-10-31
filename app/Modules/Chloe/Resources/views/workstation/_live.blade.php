{{--
    Live updates for a Workstation page (see public/js/chloe-workstation-live.js).

    $liveKind  'cgs' | 'student'
    $liveStats true on a page that shows the stats bar / room cards (CGS only)

    No inline handlers and no inline style attributes: the script is a
    same-origin file, configured by data attributes on #wsl-config, and the
    two small rules below only restyle classes the script adds.
--}}
@php
    $liveKind = $liveKind ?? 'cgs';
    $isStudentLive = $liveKind === 'student';
@endphp
<style>
    .wsl-status-row { min-height: 18px; margin: 2px 0 4px; font-size: 12px; line-height: 1.5; color: #6B7280; }
    @keyframes wsl-flash { from { background-color: #FFF1B8; } to { background-color: rgba(255, 241, 184, 0); } }
    .wsl-flash { border-radius: 4px; animation: wsl-flash 1.5s ease-out; }
    @media (prefers-reduced-motion: reduce) { .wsl-flash { animation: none; background-color: #FFF1B8; } }
</style>
<div id="wsl-config" hidden
     data-kind="{{ $liveKind }}"
     data-version="{{ \App\Modules\Chloe\Support\WorkstationLiveVersion::current($isStudentLive ? auth()->user() : null) }}"
     data-version-url="{{ $isStudentLive ? route('workstation.live.student-version') : route('workstation.cgs.live.version') }}"
     @if (! $isStudentLive && ($liveStats ?? false)) data-stats-url="{{ route('workstation.cgs.live.stats') }}" @endif
     data-interval="{{ $isStudentLive ? 20000 : 15000 }}"></div>
<div class="wsl-status-row"><span id="wsl-status" aria-live="polite"></span></div>
<script src="{{ asset('js/chloe-workstation-live.js') }}"></script>
