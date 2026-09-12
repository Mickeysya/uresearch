{{--
    The student-only section of the sidebar: a fixed "Track My Applications"
    link plus two trees. Attendance is fixed (every student has one); My
    Application is driven by ModuleRegistry::submittable(), same as the
    "New Application" section it replaces, so a new module's create form
    appears here on its own -- nothing to edit when Norhanis, Hani, Jason or
    Chloe ships one.

    Expects: $submittable, $myApplicationOpen
--}}
<a href="{{ route('applications.index') }}" class="nav-item @if(request()->routeIs('applications.*')) active @endif" title="Track My Applications">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </span>
    <span class="nav-label">Track My Applications</span>
</a>

<div class="nav-tree @if(request()->routeIs('attendance.*')) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="Attendance"
            aria-expanded="@if(request()->routeIs('attendance.*')) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 15l2 2 4-4"/></svg>
        </span>
        <span class="nav-label">Attendance</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            <a href="{{ route('attendance.overview') }}" class="nav-subitem @if(request()->routeIs('attendance.overview')) active @endif">Overview</a>
            <a href="{{ route('attendance.history') }}" class="nav-subitem @if(request()->routeIs('attendance.history')) active @endif">Attendance History</a>
        </div>
    </div>
</div>

<div class="nav-tree @if($myApplicationOpen) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="My Application"
            aria-expanded="@if($myApplicationOpen) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        </span>
        <span class="nav-label">My Application</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            @foreach ($submittable as $module)
                <a href="{{ route($module->createRoute()) }}" class="nav-subitem @if(request()->routeIs($module->createRoute())) active @endif">{{ $module->label() }}</a>
            @endforeach
        </div>
    </div>
</div>
