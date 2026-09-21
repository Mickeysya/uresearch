{{--
    Quick actions.

    Two sources, in this order:

    1. Whatever the MODULES declare for this role through ProvidesLinks --
       the same list the sidebar builds from, so a teammate adding a screen
       for Chairs or Supervisors gets it here without Core being edited.
       These come first because they are the role's actual work.

    2. The Core destinations every approver uses. The sidebar has them too,
       but a dashboard is where someone lands, and "the files on my
       applications" is a click people look for here rather than hunting the
       rail for it.

    Icons are matched by route name rather than left to a default, because a
    grid of identical document icons is decoration, not a signpost.

    Every tile points at a route that exists -- the same rule the CGS Quick
    Shortcuts panel states. A dead tile on a dashboard is worse than one
    fewer tile, which is why the list is filtered through Route::has().
--}}
@php
    // Route prefix => icon. First match wins; anything unmatched keeps 'doc',
    // which is the right fallback for a module link Core knows nothing about.
    $icons = [
        'examiner' => 'people',
        'appointment-letter' => 'people',
        'hardbound.signature' => 'pencil',
        'documents' => 'folder',
        'calendar' => 'calendar',
        'notifications' => 'bell',
        'profile' => 'person',
        'help' => 'info',
    ];

    $iconFor = function (string $route) use ($icons) {
        foreach ($icons as $prefix => $icon) {
            if (str_starts_with($route, $prefix)) {
                return $icon;
            }
        }

        return 'doc';
    };

    // Core screens an approver reaches from here. Not role-specific, so they
    // are listed once rather than declared by every module.
    $core = [
        ['label' => 'Documents', 'route' => 'documents.index'],
        ['label' => 'Calendar', 'route' => 'calendar.index'],
        ['label' => 'Notifications', 'route' => 'notifications.index'],
        ['label' => 'My Profile', 'route' => 'profile.show'],
        ['label' => 'Help & Support', 'route' => 'help.index'],
    ];

    $actions = collect($shortcuts)->concat($core)
        ->filter(fn ($link) => \Illuminate\Support\Facades\Route::has($link['route']))
        ->unique('route')
        ->values();
@endphp

<section class="sdash-card approver-panel approver-shortcuts">
    <header class="sdash-card-head">
        <h3>Quick actions</h3>
    </header>

    @if ($actions->isEmpty())
        <div class="empty-state">Nothing beyond your queues.</div>
    @else
        <ul class="approver-shortcut-list approver-scroll">
            @foreach ($actions as $link)
                <li>
                    <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="approver-shortcut">
                        <span class="approver-shortcut-icon" aria-hidden="true">
                            @include('core::dashboard.partials.icon', ['name' => $iconFor($link['route'])])
                        </span>
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
