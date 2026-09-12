@extends('core::layouts.app')

@section('title', 'Notifications')

@section('content')
{{--
    The notification feed, for every role.

    Rows are Laravel DatabaseNotification records. Everything read from
    $note->data is optional and falls back, so a notification stored by a
    teammate's module renders sensibly with no edit here — only `title` is
    really needed, and even that falls back to the class name.

    Grouped by day rather than shown as one flat list: a feed is read by
    "what happened recently", and a date heading answers that faster than a
    relative timestamp on every row.
--}}
@php
    // Icon and colour by what the notification says happened. Unknown types
    // get the neutral bell, so nothing renders blank.
    $toneFor = function ($data, $type) {
        if (array_key_exists('approved', $data)) {
            return $data['approved'] ? 'good' : 'critical';
        }
        return str_contains(strtolower($type), 'risk') ? 'warn' : 'info';
    };

    $iconFor = fn ($tone) => match ($tone) {
        'good' => 'check',
        'critical' => 'cross',
        'warn' => 'warning',
        default => 'bell',
    };

    $groups = $notifications->getCollection()->groupBy(function ($note) {
        $d = $note->created_at;
        if ($d->isToday()) return 'Today';
        if ($d->isYesterday()) return 'Yesterday';
        return $d->isCurrentYear() ? $d->format('j F') : $d->format('j F Y');
    });
@endphp

<div class="notif-page">

    <header class="notif-header">
        <div class="notif-heading">
            <h2>Notifications</h2>
            <p>
                @if ($unreadCount > 0)
                    You have <b>{{ $unreadCount }}</b> unread of {{ $totalCount }}.
                @elseif ($totalCount > 0)
                    All {{ $totalCount }} read. Nothing needs your attention.
                @else
                    Alerts about your applications will appear here.
                @endif
            </p>
        </div>

        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}" class="notif-readall">
                @csrf
                <button type="submit">Mark all as read</button>
            </form>
        @endif
    </header>

    <nav class="notif-filters" aria-label="Filter notifications">
        <a href="{{ route('notifications.index') }}"
           class="notif-filter @if($filter === 'all') active @endif">
            All <span>{{ $totalCount }}</span>
        </a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}"
           class="notif-filter @if($filter === 'unread') active @endif">
            Unread <span>{{ $unreadCount }}</span>
        </a>
    </nav>

    @forelse ($groups as $heading => $rows)
        <section class="notif-group">
            <h3 class="notif-group-heading">{{ $heading }}</h3>

            <div class="notif-list">
                @foreach ($rows as $note)
                    @php
                        $data = $note->data ?? [];
                        $tone = $toneFor($data, $note->type);
                        $unread = $note->read_at === null;
                        $title = $data['title'] ?? class_basename($note->type);
                    @endphp

                    {{-- A whole row is one submit button: following a
                         notification marks it read, which is a state change
                         and so must not be a GET. --}}
                    <form method="POST" action="{{ route('notifications.read', $note->id) }}" class="notif-row-form">
                        @csrf
                        <button type="submit" class="notif-row @if($unread) is-unread @endif">
                            <span class="notif-icon tone-{{ $tone }}" aria-hidden="true">
                                @include('core::dashboard.partials.icon', ['name' => $iconFor($tone)])
                            </span>

                            <span class="notif-body">
                                <span class="notif-title">{{ $title }}</span>
                                <span class="notif-meta">
                                    @if (! empty($data['module']))
                                        <span class="notif-tag">{{ $data['module'] }}</span>
                                    @endif
                                    @if (! empty($data['next_stage']))
                                        <span>Now with {{ $data['next_stage'] }}</span>
                                    @endif
                                    <span>{{ $note->created_at->format('g:ia') }}</span>
                                </span>
                            </span>

                            @if ($unread)
                                <span class="notif-dot" aria-label="Unread"></span>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </section>
    @empty
        <div class="notif-empty">
            <span class="notif-empty-icon" aria-hidden="true">
                @include('core::dashboard.partials.icon', ['name' => 'bell'])
            </span>
            @if ($filter === 'unread')
                <p class="notif-empty-title">Nothing unread</p>
                <p>You are all caught up. <a href="{{ route('notifications.index') }}">See all notifications</a>.</p>
            @else
                <p class="notif-empty-title">No notifications yet</p>
                <p>Decisions on your applications, attendance warnings and reminders will appear here.</p>
            @endif
        </div>
    @endforelse

    {{-- Hand-rolled rather than {{ $notifications->links() }}: Laravel's
         default pagination views are Tailwind markup, and this app has no
         Tailwind. Only rendered when there is more than one page. --}}
    @if ($notifications->hasPages())
        <nav class="notif-pager" aria-label="Pagination">
            @if ($notifications->onFirstPage())
                <span class="notif-page-link is-disabled">Newer</span>
            @else
                <a href="{{ $notifications->previousPageUrl() }}" class="notif-page-link" rel="prev">Newer</a>
            @endif

            <span class="notif-page-count">
                Page {{ $notifications->currentPage() }} of {{ $notifications->lastPage() }}
            </span>

            @if ($notifications->hasMorePages())
                <a href="{{ $notifications->nextPageUrl() }}" class="notif-page-link" rel="next">Older</a>
            @else
                <span class="notif-page-link is-disabled">Older</span>
            @endif
        </nav>
    @endif

</div>
@endsection
