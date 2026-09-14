{{--
    The system administrator's section of the sidebar.

    Follows Sample/ADMIN_DASHBOARD.png with two deliberate departures, both
    because the mockup asks for things no scope document describes:

      - **Courses is not here.** "Course" appears nowhere in the six scope
        documents, there is no `courses` table, and `users.programme` is a
        free-text string. A nav item for an unscoped feature only creates
        work for whoever tries to follow it.
      - **Faculty is not a separate item.** Everywhere the documents say
        "Faculty" they mean an approver role or the FOE/FSMC attribute on a
        user, never a staff directory. Staff accounts are reached through
        Users and Roles.

    Oversight sections (Students, Applications, Attendance Monitoring) are
    read-only: `queuesForRole('admin')` is empty by design, so the admin never
    acts on an application. Acting on one is CGS's job.

    Users and Roles, System Settings and Audit Logs are the administrator's
    actual scope, per technical.md and jason.md §5.5.
--}}
@php
    $applicationsOpen = request()->routeIs('admin.applications.*');
    $attendanceOpen = request()->routeIs('admin.attendance.*');
    $reportsOpen = request()->routeIs('admin.reports.*');
    $status = request()->query('status');
@endphp

<a href="{{ route('admin.students.index') }}" class="nav-item @if(request()->routeIs('admin.students.*')) active @endif" title="Students">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 5.6"/><path d="M18.5 20a6.5 6.5 0 0 0-3-5.5"/></svg>
    </span>
    <span class="nav-label">Students</span>
</a>

{{-- ---- Applications (read-only, filtered by status) -------------------- --}}
<div class="nav-tree @if($applicationsOpen) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="Applications"
            aria-expanded="@if($applicationsOpen) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
        </span>
        <span class="nav-label">Applications</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            <a href="{{ route('admin.applications.index') }}" class="nav-subitem @if($applicationsOpen && ! $status) active @endif">All Applications</a>
            <a href="{{ route('admin.applications.index', ['status' => 'pending']) }}" class="nav-subitem @if($status === 'pending') active @endif">Pending</a>
            <a href="{{ route('admin.applications.index', ['status' => 'approved']) }}" class="nav-subitem @if($status === 'approved') active @endif">Approved</a>
            <a href="{{ route('admin.applications.index', ['status' => 'rejected']) }}" class="nav-subitem @if($status === 'rejected') active @endif">Rejected</a>
        </div>
    </div>
</div>

{{-- ---- Attendance Monitoring (read-only) ------------------------------- --}}
<div class="nav-tree @if($attendanceOpen) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="Attendance Monitoring"
            aria-expanded="@if($attendanceOpen) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-7 5 14L17 12h4"/></svg>
        </span>
        <span class="nav-label">Attendance Monitoring</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            <a href="{{ route('admin.attendance.overview') }}" class="nav-subitem @if(request()->routeIs('admin.attendance.overview')) active @endif">Overview</a>
            <a href="{{ route('admin.attendance.at-risk') }}" class="nav-subitem @if(request()->routeIs('admin.attendance.at-risk')) active @endif">At-Risk Students</a>
        </div>
    </div>
</div>

{{-- ---- Reports & Analytics --------------------------------------------- --}}
<div class="nav-tree @if($reportsOpen) open @endif">
    <button type="button" class="nav-item nav-tree-trigger" title="Reports and Analytics"
            aria-expanded="@if($reportsOpen) true @else false @endif">
        <span class="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/></svg>
        </span>
        <span class="nav-label">Reports and Analytics</span>
        <span class="nav-chevron">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
        </span>
    </button>
    <div class="nav-tree-panel">
        <div class="nav-tree-items">
            <a href="{{ route('admin.reports.index') }}" class="nav-subitem @if(request()->routeIs('admin.reports.index')) active @endif">System Overview</a>
            <a href="{{ route('admin.reports.approval-times') }}" class="nav-subitem @if(request()->routeIs('admin.reports.approval-times')) active @endif">Approval Times</a>
            <a href="{{ route('admin.reports.bottlenecks') }}" class="nav-subitem @if(request()->routeIs('admin.reports.bottlenecks')) active @endif">Bottlenecks</a>
        </div>
    </div>
</div>

{{-- ---- The administrator's own scope ------------------------------------ --}}
<div class="nav-section-label"><span class="nav-label">Administration</span></div>

<a href="{{ route('admin.users.index') }}" class="nav-item @if(request()->routeIs('admin.users.*')) active @endif" title="Users and Roles">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/><path d="M19 3.5v4"/><path d="M17 5.5h4"/></svg>
    </span>
    <span class="nav-label">Users and Roles</span>
</a>

<a href="{{ route('settings.index') }}" class="nav-item @if(request()->routeIs('settings.*')) active @endif" title="System Settings">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </span>
    <span class="nav-label">System Settings</span>
</a>

<a href="{{ route('admin.audit.index') }}" class="nav-item @if(request()->routeIs('admin.audit.*')) active @endif" title="Audit Logs">
    <span class="nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="13" y2="13"/><line x1="8" y1="17" x2="11" y2="17"/></svg>
    </span>
    <span class="nav-label">Audit Logs</span>
</a>
