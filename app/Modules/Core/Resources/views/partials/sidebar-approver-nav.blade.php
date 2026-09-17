{{--
    Every non-student role's section of the sidebar: whichever (module,
    stage) pairs ModuleRegistry::queuesForRole() says this role owns. This
    is the same list for every one of the thirteen roles in Support\Role --
    a Chair sees the queues a Chair owns, an Academic Executive sees theirs,
    and so on, with nothing here naming a specific role. Register a module
    with a stage for a new role and it appears for that role on its own.

    FLAT OR A TREE, decided by how many queues the role actually owns. The
    spread is wide and fixed by the modules, not by preference:

        supervisor 7 · academic_exec 6 · chair 5 · dean_pgr 4
        senior_director_cgs 3 · manager_cgs 1 · senior_exec_cgs 1
        faculty 1 · registry 1

    (Non-Executive CGS owns 11 and does not use this partial at all; it has
    its own nav with its own trees.) A collapsing tree around one queue is
    a click in front of a single link, and a flat list of seven pushes
    Notification and Calendar off the fold. So: four or more collapses,
    three or fewer stays flat. The tree opens itself whenever you are on
    one of its pages, so the extra click is only ever paid on the way in
    from somewhere else.

    Expects: $queues
--}}
@php
    $queueRoutes = collect($queues)->map(fn ($q) => $q['module']->queueRoute())->unique();
    $queuesOpen = $queueRoutes->contains(fn ($r) => request()->routeIs($r));

    // Four is where the list stops being scannable at a glance.
    $collapse = count($queues) >= 4;
@endphp

@if (! empty($queues))
    @if ($collapse)
        <div class="nav-tree @if($queuesOpen) open @endif">
            <button type="button" class="nav-item nav-tree-trigger" title="Pending My Action"
                    aria-expanded="@if($queuesOpen) true @else false @endif">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>
                </span>
                <span class="nav-label">Pending My Action</span>
                <span class="nav-chevron">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                </span>
            </button>
            <div class="nav-tree-panel">
                <div class="nav-tree-items">
                    @foreach ($queues as $queue)
                        <a href="{{ route($queue['module']->queueRoute(), ['stage' => $queue['stage']->key]) }}"
                           title="{{ $queue['module']->label() }}"
                           class="nav-subitem @if(request()->routeIs($queue['module']->queueRoute()) && request()->query('stage', $queue['stage']->key) === $queue['stage']->key) active @endif">
                            {{ $queue['module']->label() }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="nav-section-label"><span class="nav-label">Pending My Action</span></div>
        @foreach ($queues as $queue)
            <a href="{{ route($queue['module']->queueRoute(), ['stage' => $queue['stage']->key]) }}"
               class="nav-item nav-item-flat" title="{{ $queue['module']->label() }}">
                <span class="nav-label">{{ $queue['module']->label() }}</span>
            </a>
        @endforeach
    @endif
@endif
