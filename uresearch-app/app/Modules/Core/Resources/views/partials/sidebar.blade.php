{{--
    Built entirely from the ModuleRegistry (see AppServiceProvider's view
    composer). The legacy sidebar.php was a hardcoded if/elseif over seven
    roles listing fourteen filenames, so every new module meant editing a file
    the whole team shared. Register a module and its links appear here on their
    own, for exactly the roles that own a stage in it.
--}}
<div class="sidebar">
    <a href="{{ route('dashboard') }}"><b>Dashboard</b></a>

    @auth
        @if (auth()->user()->isStudent())
            <a href="{{ route('applications.index') }}"><b>Track My Applications</b></a>

            @if (! empty($submittable))
                <div class="section-label">New Application</div>
                @foreach ($submittable as $module)
                    <a href="{{ route($module->createRoute()) }}" class="sub-link">{{ $module->label() }}</a>
                @endforeach
            @endif
        @else
            @if (! empty($queues))
                <div class="section-label">Pending My Action</div>
                @foreach ($queues as $queue)
                    <a href="{{ route($queue['module']->queueRoute(), ['stage' => $queue['stage']->key]) }}"
                       class="sub-link">{{ $queue['module']->label() }}</a>
                @endforeach
            @else
                <div class="section-label">No queues assigned</div>
            @endif
        @endif

        @if (! empty($extraLinks))
            <div class="section-label">Actions</div>
            @foreach ($extraLinks as $link)
                <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="sub-link">{{ $link['label'] }}</a>
            @endforeach
        @endif

        <a href="{{ route('logout') }}" class="logout-link"
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Log out</a>

        <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">
            @csrf
        </form>
    @endauth
</div>
