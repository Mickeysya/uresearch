{{--
    Every non-student role's section of the sidebar: whichever (module,
    stage) pairs ModuleRegistry::queuesForRole() says this role owns. This
    is the same list for every one of the thirteen roles in Support\Role --
    a Chair sees the queues a Chair owns, an Academic Executive sees theirs,
    and so on, with nothing here naming a specific role. Register a module
    with a stage for a new role and it appears for that role on its own.

    Expects: $queues
--}}
@if (! empty($queues))
    <div class="nav-section-label"><span class="nav-label">Pending My Action</span></div>
    @foreach ($queues as $queue)
        <a href="{{ route($queue['module']->queueRoute(), ['stage' => $queue['stage']->key]) }}"
           class="nav-item nav-item-flat" title="{{ $queue['module']->label() }}">
            <span class="nav-label">{{ $queue['module']->label() }}</span>
        </a>
    @endforeach
@endif
