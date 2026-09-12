@extends('core::layouts.app')

@section('title', 'Audit Logs')

@section('content')
{{--
    The audit trail. Rows are spatie/laravel-activitylog records, so anything
    that calls activity() anywhere in the portal appears here with no edit to
    this file — including a teammate's module.

    Reuses the notification feed's row styling rather than inventing a second
    list treatment for the same shape of content.
--}}
@php
    $toneFor = fn ($a) => match ($a->properties['action'] ?? null) {
        'rejected' => 'critical',
        'approved', 'endorsed', 'reviewed' => 'good',
        'viewed' => 'info',
        'uploaded' => 'warn',
        default => 'info',
    };
    $iconFor = fn ($name) => match ($name) {
        'document' => 'doc',
        'auth' => 'people',
        'workflow' => 'check',
        default => 'bell',
    };
@endphp

<div class="notif-page">
    <header class="notif-header">
        <div class="notif-heading">
            <h2>Audit Logs</h2>
            <p>
                @if ($total > 0)
                    <b>{{ number_format($total) }}</b> recorded {{ Str::plural('action', $total) }} — sign-ins, decisions, uploads and document views.
                @else
                    Nothing has been recorded yet.
                @endif
            </p>
        </div>
    </header>

    @if ($logNames->isNotEmpty())
        <nav class="notif-filters" aria-label="Filter by log">
            <a href="{{ route('admin.audit.index') }}" class="notif-filter @if(! $log) active @endif">
                All <span>{{ number_format($total) }}</span>
            </a>
            @foreach ($logNames as $name)
                <a href="{{ route('admin.audit.index', ['log' => $name]) }}"
                   class="notif-filter @if($log === $name) active @endif">{{ ucfirst($name) }}</a>
            @endforeach
        </nav>
    @endif

    <div class="notif-list">
        @forelse ($activities as $a)
            @php $tone = $toneFor($a); @endphp
            <div class="notif-row audit-row">
                <span class="notif-icon tone-{{ $tone }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $iconFor($a->log_name)])
                </span>

                <span class="notif-body">
                    <span class="notif-title">{{ $a->description }}</span>
                    <span class="notif-meta">
                        <span class="notif-tag">{{ $a->causer->name ?? 'System' }}</span>
                        @foreach (collect($a->properties)->except('action')->take(3) as $key => $value)
                            @if (! is_array($value) && $value !== null && $value !== '')
                                <span>{{ Str::headline($key) }}: {{ Str::limit((string) $value, 30) }}</span>
                            @endif
                        @endforeach
                        <span>{{ $a->created_at->format('j M Y, g:ia') }}</span>
                    </span>
                </span>

                <span class="audit-log-name">{{ $a->log_name }}</span>
            </div>
        @empty
            <div class="notif-empty">
                <span class="notif-empty-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'search'])
                </span>
                <p class="notif-empty-title">No activity recorded</p>
                <p>Sign-ins, approvals, uploads and document views appear here as they happen.</p>
            </div>
        @endforelse
    </div>

    @if ($activities->hasPages())
        <nav class="notif-pager" aria-label="Pagination">
            @if ($activities->onFirstPage())
                <span class="notif-page-link is-disabled">Newer</span>
            @else
                <a href="{{ $activities->previousPageUrl() }}" class="notif-page-link" rel="prev">Newer</a>
            @endif
            <span class="notif-page-count">Page {{ $activities->currentPage() }} of {{ $activities->lastPage() }}</span>
            @if ($activities->hasMorePages())
                <a href="{{ $activities->nextPageUrl() }}" class="notif-page-link" rel="next">Older</a>
            @else
                <span class="notif-page-link is-disabled">Older</span>
            @endif
        </nav>
    @endif
</div>
@endsection
