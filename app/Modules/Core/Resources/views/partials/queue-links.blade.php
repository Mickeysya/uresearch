{{--
    The queue links for one role, grouped by module.

    WHY THIS EXISTS. A module contributes one queue per stage it owns for
    this role, and the nav printed the MODULE's label for each. Hani's
    re-viva has four consecutive stages all owned by the Academic Executive,
    so their sidebar read "Re-viva Monitoring" four times with nothing to
    tell them apart -- four links you cannot choose between.

    So: one link per module as before, unless a module owns more than one
    stage for this role, in which case it gets a tree of its own and the
    items are the STAGE names. Data-driven, so it is not four-stage re-viva
    that is special-cased here -- any module with two stages for one role
    gets the same treatment, and a module with one is untouched.

    Expects: $queues. Optional: $activeRoute (a route the parent already
    knows is current, unused here but kept for callers that pass it).
--}}
@php
    // Keyed by module so consecutive stages of the same module group even if
    // the registry ever returns them out of order.
    $byModule = collect($queues)->groupBy(fn ($q) => $q['module']->key());

    $isCurrent = function (array $queue): bool {
        return request()->routeIs($queue['module']->queueRoute())
            && request()->query('stage', $queue['stage']->key) === $queue['stage']->key;
    };
@endphp

@foreach ($byModule as $group)
    @php $module = $group->first()['module']; @endphp

    @if ($group->count() === 1)
        @php $queue = $group->first(); @endphp
        <a href="{{ route($module->queueRoute(), ['stage' => $queue['stage']->key]) }}"
           title="{{ $module->label() }}"
           class="nav-subitem @if($isCurrent($queue)) active @endif">
            {{ $module->label() }}
        </a>
    @else
        @php $groupOpen = $group->contains(fn ($q) => $isCurrent($q)); @endphp

        {{-- Opens itself when you are on one of its stages, so the extra
             click is only ever paid coming in from somewhere else. --}}
        <div class="nav-tree nav-tree-nested @if($groupOpen) open @endif">
            <button type="button" class="nav-item nav-tree-trigger" title="{{ $module->label() }}"
                    aria-expanded="@if($groupOpen) true @else false @endif">
                <span class="nav-label">{{ $module->label() }}</span>
                <span class="nav-count">{{ $group->count() }}</span>
                <span class="nav-chevron">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                </span>
            </button>

            <div class="nav-tree-panel">
                <div class="nav-tree-items">
                    @foreach ($group as $queue)
                        <a href="{{ route($module->queueRoute(), ['stage' => $queue['stage']->key]) }}"
                           title="{{ $module->label() }}: {{ $queue['stage']->label }}"
                           class="nav-subitem @if($isCurrent($queue)) active @endif">
                            {{ $queue['stage']->label }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
@endforeach
