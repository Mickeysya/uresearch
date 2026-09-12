{{--
    The Centre for Graduate Studies section of the sidebar — the four CGS
    staff roles (see Role::cgsTeam()).

    THE APPLICATIONS TREE IS NOT A HARDCODED LIST. It renders whatever
    ModuleRegistry::queuesForRole() says this role owns, exactly as the
    generic approver nav does. That matters for two reasons:

      1. Non-Executive CGS owns five queues, not four — international travel
         routes through `cgs_review` and is easy to forget when listing CGS's
         work from memory. The registry cannot forget it.
      2. When Jason ships Hardbound Submission (Non-Exec CGS → Senior Exec
         CGS), both queues appear here on their own, with nothing to edit.

    Manager CGS and Senior Executive CGS own no queue yet, so their tree is
    empty and says so, rather than listing another role's work.

    Expects: $queues, $extraLinks
--}}
@php
    // Open a tree when the page you are on lives inside it.
    $queueRoutes = collect($queues)->map(fn ($q) => $q['module']->queueRoute())->unique();
    $applicationsOpen = $queueRoutes->contains(fn ($r) => request()->routeIs($r));
    $attendanceOpen = request()->routeIs('attendance.*') || request()->routeIs('cgs.attendance.*');

    // Attendance links a module declared through ProvidesLinks (the CSV
    // upload and the at-risk list) belong in the Attendance tree, not loose
    // under "Actions" where the generic nav puts them.
    $attendanceLinkRoutes = ['attendance.upload.form', 'attendance.at-risk'];
    $attendanceLinks = collect($extraLinks ?? [])
        ->filter(fn ($l) => in_array($l['route'], $attendanceLinkRoutes, true));
@endphp

{{-- ---- Applications ------------------------------------------------- --}}
<div class="nav-tree @if($applicationsOpen) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="Applications"
            aria-expanded="@if($applicationsOpen) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>
        </span>
        <span class="nav-label">Applications</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            @forelse ($queues as $queue)
                <a href="{{ route($queue['module']->queueRoute(), ['stage' => $queue['stage']->key]) }}"
                   class="nav-subitem @if(request()->routeIs($queue['module']->queueRoute()) && request()->query('stage', $queue['stage']->key) === $queue['stage']->key) active @endif">
                    {{ $queue['module']->label() }}
                </a>
            @empty
                <span class="nav-subitem nav-subitem-muted">No queues assigned yet</span>
            @endforelse
        </div>
    </div>
</div>

{{-- ---- Attendance Monitoring ----------------------------------------- --}}
@if ($attendanceLinks->isNotEmpty())
    <div class="nav-tree @if($attendanceOpen) open @endif">
        <button type="button" class="nav-item nav-tree-trigger" title="Attendance Monitoring"
                aria-expanded="@if($attendanceOpen) true @else false @endif">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 15l2 2 4-4"/></svg>
            </span>
            <span class="nav-label">Attendance Monitoring</span>
            <span class="nav-chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </span>
        </button>
        <div class="nav-tree-panel">
            <div class="nav-tree-items">
                <a href="{{ route('cgs.attendance.overview') }}" class="nav-subitem @if(request()->routeIs('cgs.attendance.overview')) active @endif">Overview</a>
                <a href="{{ route('cgs.attendance.students') }}" class="nav-subitem @if(request()->routeIs('cgs.attendance.students')) active @endif">Student List</a>
                <a href="{{ route('attendance.at-risk') }}" class="nav-subitem @if(request()->routeIs('attendance.at-risk')) active @endif">Attendance Alerts</a>
                {{-- The way attendance data gets into the system at all: CGS
                     exports from UTrace and uploads the CSV here. --}}
                <a href="{{ route('attendance.upload.form') }}" class="nav-subitem @if(request()->routeIs('attendance.upload')) active @endif">Upload Attendance CSV</a>
            </div>
        </div>
    </div>
@endif

{{-- ---- Standalone ---------------------------------------------------- --}}
<a href="{{ route('cgs.students.index') }}" class="nav-item @if(request()->routeIs('cgs.students.*')) active @endif" title="Students">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 5.6"/><path d="M18.5 20a6.5 6.5 0 0 0-3-5.5"/></svg>
    </span>
    <span class="nav-label">Students</span>
</a>

<a href="{{ route('cgs.reports.index') }}" class="nav-item @if(request()->routeIs('cgs.reports.*')) active @endif" title="Reports and Analytics">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/></svg>
    </span>
    <span class="nav-label">Reports and Analytics</span>
</a>

{{-- Anything a module declared that is NOT already placed in a tree above,
     so a teammate's new ProvidesLinks entry still reaches CGS. --}}
@php
    $unplacedLinks = collect($extraLinks ?? [])
        ->reject(fn ($l) => in_array($l['route'], $attendanceLinkRoutes, true));
@endphp
@if ($unplacedLinks->isNotEmpty())
    <div class="nav-section-label"><span class="nav-label">Actions</span></div>
    @foreach ($unplacedLinks as $link)
        <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="nav-item nav-item-flat" title="{{ $link['label'] }}">
            <span class="nav-label">{{ $link['label'] }}</span>
        </a>
    @endforeach
@endif
