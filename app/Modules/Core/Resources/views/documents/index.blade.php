@extends('core::layouts.app')

@section('title', 'Documents')

@section('content')
<div class="rpd-page">
    <header class="rpd-header">
        <div>
            <h2>Documents</h2>
            <p class="queue-meta">
                @if ($documents->isEmpty() && $search === '' && $module === 'all')
                    Files you upload with an application appear here automatically.
                @else
                    {{ $documents->count() }} {{ Str::plural('file', $documents->count()) }}@if ($totalBytes > 0) · {{ round($totalBytes / 1024 / 1024, 1) }} MB @endif
                    @unless ($isOwnList) · files from applications you have handled or are handling @endunless
                @endif
            </p>
        </div>

        <form method="GET" action="{{ route('documents.index') }}" class="doc-search">
            @if ($module !== 'all')<input type="hidden" name="module" value="{{ $module }}">@endif
            <input type="search" name="q" id="doc-search" value="{{ $search }}"
                   placeholder="Search by name, type or reference" aria-label="Search documents">
        </form>
    </header>

    @if ($byModule->isNotEmpty())
        <nav class="notif-filters" aria-label="Filter by application type">
            <a href="{{ route('documents.index', array_filter(['q' => $search ?: null])) }}"
               class="notif-filter @if($module === 'all') active @endif">
                All <span>{{ $byModule->sum() }}</span>
            </a>
            @foreach ($byModule as $key => $count)
                <a href="{{ route('documents.index', array_filter(['module' => $key, 'q' => $search ?: null])) }}"
                   class="notif-filter @if($module === $key) active @endif">
                    {{ $moduleLabels[$key] ?? $key }} <span>{{ $count }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @if ($documents->isEmpty())
        <div class="empty-state">
            @if ($search !== '' || $module !== 'all')
                <p>No document matches that.</p>
                <p class="queue-meta"><a href="{{ route('documents.index') }}">Clear the filters</a></p>
            @else
                <p>Nothing here yet.</p>
                <p class="queue-meta">
                    Receipts, supporting letters and generated certificates are filed here as soon as
                    they are attached to an application.
                </p>
            @endif
        </div>
    @else
        @foreach ($groups as $heading => $rows)
            @continue($rows->isEmpty())
            <section class="doc-group">
                <h3 class="notif-group-heading">{{ $heading }}</h3>

                <ul class="doc-grid">
                    @foreach ($rows as $doc)
                        <li class="doc-card">
                            <span class="doc-icon" aria-hidden="true">
                                @include('core::dashboard.partials.icon', ['name' => 'doc'])
                            </span>

                            <div class="doc-main">
                                <a href="{{ $doc->url() }}" class="doc-name">{{ $doc->original_name }}</a>
                                <p class="doc-meta">
                                    {{ $doc->doc_type }}
                                    &middot; {{ $moduleLabels[$doc->application->module_type] ?? $doc->application->module_type }}
                                    &middot; <a href="{{ route('applications.show', $doc->application) }}">{{ $doc->application->reference() }}</a>
                                    @unless ($isOwnList)
                                        &middot; {{ $doc->application->student?->name }}
                                    @endunless
                                </p>
                            </div>

                            <div class="doc-side">
                                <span class="doc-size">
                                    {{ $doc->size_bytes >= 1048576
                                        ? round($doc->size_bytes / 1048576, 1).' MB'
                                        : max(1, round($doc->size_bytes / 1024)).' KB' }}
                                </span>
                                <span class="doc-date">{{ $doc->created_at->format('j M') }}</span>
                            </div>

                            @unless ($doc->exists())
                                {{-- The row exists but the file behind it does not. Saying so
                                     beats a download that 404s with no explanation. --}}
                                <span class="doc-missing" title="The stored file is missing">File missing</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
@endsection
