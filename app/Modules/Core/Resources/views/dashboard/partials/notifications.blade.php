{{--
    "Recent Notifications" — the stored (database-channel) feed.

    Rows are Laravel DatabaseNotification records. A notification appears here
    by adding 'database' to its via(); the shape it stores is up to the
    notification's own toArray(). This partial reads 'title' and falls back to
    the class name, so a notification that has not defined toArray() keys
    still renders rather than blowing up.

    Each row is clickable and behaves like its counterpart in the full feed —
    it marks the notification read and follows through to the application it
    concerns, so the dashboard is a shortcut into the same flow rather than a
    dead-end summary.
--}}
<section class="sdash-card">
    @if (isset($unavailable['notifications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 3])
    @endif

    <header class="sdash-card-head">
        <h3>Recent Notifications</h3>
        <a href="{{ route('notifications.index') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($notifications as $note)
            @php
                $data = $note->data ?? [];
                $title = $data['title'] ?? class_basename($note->type);
            @endphp

            {{-- Same behaviour as a row in the full feed: following a
                 notification marks it read and continues to whatever it was
                 about. A state change, so a form and not a link. --}}
            <form method="POST" action="{{ route('notifications.read', $note->id) }}" class="sdash-note-form">
                @csrf
                <button type="submit" class="sdash-note-row {{ $note->read_at ? '' : 'is-unread' }}">
                    <span class="sdash-note-bell" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => 'bell'])
                    </span>

                    <span class="sdash-row-main">
                        <span class="sdash-note-text">{{ $title }}</span>
                        <span class="sdash-row-sub">{{ $note->created_at->diffForHumans() }}</span>
                    </span>

                    @if (! $note->read_at)
                        <span class="sdash-unread-dot" aria-label="Unread"></span>
                    @endif
                </button>
            </form>
        @empty
            <p class="sdash-empty">Nothing yet. Decisions on your applications will appear here.</p>
        @endforelse
    </div>
</section>
