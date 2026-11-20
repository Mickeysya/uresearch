@extends('core::layouts.app')

@section('title', 'Documents')

@php
    $filtered = $search !== '' || $module !== 'all' || $applicationId;
    // Filter links keep every other filter, so narrowing never resets the rest.
    $keep = array_filter([
        'q' => $search ?: null,
        'module' => $module !== 'all' ? $module : null,
        'application' => $applicationId,
        'range' => $windowed ? null : 'all',
    ]);
@endphp

@section('content')
<div class="rpd-page">
    <x-core::page-header title="Documents">
        <x-slot:subtitle>
            @if ($documents->total() === 0 && ! $filtered)
                Files you upload with an application appear here automatically.
            @else
                {{ number_format($documents->total()) }} {{ Str::plural('file', $documents->total()) }}@if ($totalBytes > 0) · {{ number_format($totalBytes / 1024 / 1024, 1) }} MB @endif
                @unless ($isOwnList) · from applications you have handled or are handling @endunless
            @endif
        </x-slot:subtitle>

        <form method="GET" action="{{ route('documents.index') }}" class="doc-search" role="search">
            @if ($module !== 'all')<input type="hidden" name="module" value="{{ $module }}">@endif
            @if ($applicationId)<input type="hidden" name="application" value="{{ $applicationId }}">@endif
            <label for="doc-search" class="doc-sr">Search documents</label>
            <input type="search" name="q" id="doc-search" value="{{ $search }}"
                   placeholder="Search files">
        </form>
    </x-core::page-header>

    @if ($byModule->isNotEmpty() || $module !== 'all')
        <nav class="notif-filters" aria-label="Filter by application type">
            <a href="{{ route('documents.index', Arr::except($keep, 'module')) }}"
               class="notif-filter @if($module === 'all') active @endif"
               @if($module === 'all') aria-current="page" @endif>
                All <span>{{ number_format($byModule->sum()) }}</span>
            </a>
            @foreach ($byModule as $key => $count)
                <a href="{{ route('documents.index', ['module' => $key] + $keep) }}"
                   class="notif-filter @if($module === $key) active @endif"
                   @if($module === $key) aria-current="page" @endif>
                    {{ $moduleLabels[$key] ?? $key }} <span>{{ number_format($count) }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div class="doc-results">
        {{-- What the list is showing, and the one click that widens it. --}}
        @if ($documents->total() > 0 || $applicationId)
        <div class="doc-scope">
            <p class="doc-range">
                @if ($documents->total() > 0)
                    Showing {{ number_format($documents->firstItem()) }}–{{ number_format($documents->lastItem()) }} of {{ number_format($documents->total()) }}
                @endif
                @if ($windowed)
                    · last {{ $recentDays }} days and anything still pending ·
                    <a href="{{ route('documents.index', ['range' => 'all'] + $keep) }}">Show all time</a>
                @elseif ($search === '' && ! $applicationId)
                    · all time ·
                    <a href="{{ route('documents.index', Arr::except($keep, 'range')) }}">Last {{ $recentDays }} days only</a>
                @endif
            </p>

            @if ($applicationId)
                <a href="{{ route('documents.index', Arr::except($keep, 'application')) }}" class="doc-chip"
                   aria-label="Remove filter {{ $applicationRef }}">
                    {{ $applicationRef ?? 'Application' }}
                    <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'cross'])</span>
                </a>
            @endif
        </div>
        @endif

        @if ($documents->isEmpty())
            <div class="empty-state">
                @if ($filtered)
                    <p>No document matches that.</p>
                    @if ($windowed === false || $search !== '')
                        <p class="queue-meta">Search covers every file, all time. Try a student's name or a reference like GAEX-2026-00202.</p>
                    @endif
                    <a href="{{ route('documents.index') }}" class="btn-secondary">Clear the filters</a>
                @elseif ($windowed)
                    <p>Nothing new in the last {{ $recentDays }} days.</p>
                    <a href="{{ route('documents.index', ['range' => 'all']) }}" class="btn-secondary">Show all time</a>
                @else
                    <p>Nothing here yet.</p>
                    <p class="queue-meta">
                        Receipts, supporting letters and generated certificates are filed here as soon as
                        they are attached to an application.
                    </p>
                @endif
            </div>
        @else
            <div class="doc-panel">
            <table class="data-table doc-table">
                <thead>
                    <tr>
                        <th scope="col">File</th>
                        <th scope="col">Application</th>
                        @unless ($isOwnList)<th scope="col">Student</th>@endunless
                        <th scope="col" class="num">Size</th>
                        <th scope="col" class="num">Uploaded</th>
                        <th scope="col"><span class="doc-sr">Download</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $doc)
                        @php $app = $doc->application; @endphp
                        <tr>
                            <td class="doc-file">
                                {{-- The viewer, a download and another page's detail view
                                     are real navigations, so RegionSwap leaves them alone. --}}
                                <x-core::doc-link :document="$doc" class="doc-name" data-region-swap-ignore>{{ $doc->original_name }}</x-core::doc-link>
                                <span class="doc-sub">
                                    {{ $doc->doc_type }}
                                    @unless ($doc->exists())
                                        <span class="doc-missing" title="The row exists but the stored file does not">File missing</span>
                                    @endunless
                                </span>
                            </td>
                            <td class="doc-app">
                                <a href="{{ route('documents.index', ['application' => $app->id]) }}"
                                   class="doc-ref" title="Show every file on {{ $app->reference() }}">{{ $app->reference() }}</a>
                                <span class="doc-sub">
                                    {{ $moduleLabels[$app->module_type] ?? $app->module_type }}
                                    · <a href="{{ route('applications.show', $app) }}" data-region-swap-ignore>Open</a>
                                </span>
                            </td>
                            @unless ($isOwnList)
                                <td class="doc-student">{{ $app->student?->name ?? '—' }}</td>
                            @endunless
                            <td class="num doc-size">
                                {{ $doc->size_bytes >= 1048576
                                    ? number_format($doc->size_bytes / 1048576, 1).' MB'
                                    : max(1, round($doc->size_bytes / 1024)).' KB' }}
                            </td>
                            <td class="num doc-date">
                                <time datetime="{{ $doc->created_at->toDateString() }}">{{ $doc->created_at->format('j M Y') }}</time>
                            </td>
                            <td class="doc-action">
                                <x-core::doc-link :document="$doc" action="download" class="doc-download"
                                                  data-region-swap-ignore aria-label="Download {{ $doc->original_name }}">
                                    @include('core::dashboard.partials.icon', ['name' => 'download'])
                                </x-core::doc-link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

            {{-- Hand-rolled like the notifications pager: Laravel's default
                 pagination views are Tailwind markup and this app has none. --}}
            @if ($documents->hasPages())
                <nav class="notif-pager" aria-label="Pagination">
                    @if ($documents->onFirstPage())
                        <span class="notif-page-link is-disabled">Previous</span>
                    @else
                        <a href="{{ $documents->previousPageUrl() }}" class="notif-page-link" rel="prev">Previous</a>
                    @endif

                    <span class="notif-page-count">
                        Page {{ number_format($documents->currentPage()) }} of {{ number_format($documents->lastPage()) }}
                    </span>

                    @if ($documents->hasMorePages())
                        <a href="{{ $documents->nextPageUrl() }}" class="notif-page-link" rel="next">Next</a>
                    @else
                        <span class="notif-page-link is-disabled">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
</div>

@include('core::partials.region-swap')
<script @cspNonce>
    RegionSwap({
        selectors: ['.page-header p', '.notif-filters', '.doc-results'],
        // Outside every selector above (it lives in the page-header's own
        // actions slot), so it navigates without ever being replaced itself.
        extraForms: ['.doc-search'],
    });
</script>
@endsection
