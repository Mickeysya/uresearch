{{--
    The three things a Chair does that are not deciding a queue. Taken from
    the modules' own ProvidesLinks, so a teammate adding a Chair-facing screen
    gets it here without Core being edited -- same list the sidebar builds
    from.
--}}
<section class="sdash-card chair-panel chair-shortcuts">
    <header class="sdash-card-head">
        <h3>Quick actions</h3>
    </header>

    @if (empty($shortcuts))
        <div class="empty-state">Nothing beyond your queues.</div>
    @else
        <ul class="chair-shortcut-list">
            @foreach ($shortcuts as $link)
                <li>
                    <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="chair-shortcut">
                        <span class="chair-shortcut-icon" aria-hidden="true">
                            @include('core::dashboard.partials.icon', ['name' => 'doc'])
                        </span>
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
