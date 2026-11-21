@extends('core::layouts.app')

@section('title', 'Documents')

@php
    use App\Modules\Core\Http\Controllers\DocumentLibraryController;

    $filtered = $search !== '' || $module !== 'all' || $applicationId;
    // Filter links keep every other filter, so narrowing never resets the rest.
    $keep = array_filter([
        'q' => $search ?: null,
        'module' => $module !== 'all' ? $module : null,
        'application' => $applicationId,
        'range' => $windowed ? null : 'all',
    ]);
    // Rows open on their own when the list is answering a question -- a
    // search, or one application -- and stay collapsed for plain browsing.
    $openRows = $search !== '' || $applicationId;
    $size = fn (int $bytes) => $bytes >= 1048576
        ? number_format($bytes / 1048576, 1).' MB'
        : max(1, round($bytes / 1024)).' KB';
@endphp

@section('content')
<div class="rpd-page">
    <x-core::page-header title="Documents">
        <x-slot:subtitle>
            @if ($groups->total() === 0 && ! $filtered)
                Files you upload with an application appear here automatically.
            @else
                {{ number_format($groups->total()) }} {{ Str::plural('application', $groups->total()) }}
                · {{ number_format($fileTotal) }} {{ Str::plural('file', $fileTotal) }}@if ($totalBytes > 0) · {{ number_format($totalBytes / 1024 / 1024, 1) }} MB @endif
                @unless ($isOwnList) · from applications you have handled or are handling @endunless
            @endif
        </x-slot:subtitle>

        <form method="GET" action="{{ route('documents.index') }}" class="doc-search" role="search">
            @if ($module !== 'all')<input type="hidden" name="module" value="{{ $module }}">@endif
            @if ($applicationId)<input type="hidden" name="application" value="{{ $applicationId }}">@endif
            <label for="doc-search" class="doc-sr">Search by student, reference, file name or document type</label>
            <input type="search" name="q" id="doc-search" value="{{ $search }}"
                   placeholder="Search student, reference or file">
        </form>
    </x-core::page-header>

    @if ($byModule->isNotEmpty() || $module !== 'all')
        {{-- Each count is applications, not files. --}}
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
        @if ($groups->total() > 0 || $applicationId)
        <div class="doc-scope">
            <p class="doc-range">
                @if ($groups->total() > 0)
                    Showing {{ number_format($groups->firstItem()) }}–{{ number_format($groups->lastItem()) }} of {{ number_format($groups->total()) }} {{ Str::plural('application', $groups->total()) }}
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

        @if ($groups->isEmpty())
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
            {{-- One <details> per application: the browser gives it Enter and
                 Space, and it works with no script at all. --}}
            <div class="doc-groups">
                @foreach ($groups as $group)
                    @php
                        $app = $group->application;
                        $count = $group->documents->count();
                    @endphp
                    <details class="doc-group" @if($openRows) open @endif>
                        <summary class="doc-group-head">
                            <span class="doc-group-chevron" aria-hidden="true"></span>
                            <span class="doc-group-main">
                                <span class="doc-group-ref">{{ $app->reference() }}</span>
                                <span class="doc-group-student">{{ $app->student?->name ?? '—' }}</span>
                            </span>
                            <span class="doc-group-meta">
                                <span class="doc-group-module">{{ $moduleLabels[$app->module_type] ?? $app->module_type }}</span>
                                <span class="doc-group-count">{{ $count }} {{ Str::plural('file', $count) }}</span>
                                @if ($group->latestUpload)
                                    <span class="doc-group-date">Latest <time datetime="{{ $group->latestUpload->toDateString() }}">{{ $group->latestUpload->format('j M Y') }}</time></span>
                                @endif
                                <x-core::status-badge :status="$app->status" />
                            </span>
                        </summary>

                        <ul class="doc-group-files">
                            @foreach ($group->documents as $doc)
                                @php $hit = DocumentLibraryController::fileMatches($doc, $search); @endphp
                                <li class="doc-file-row @if($hit) is-match @endif">
                                    <div class="doc-file-id">
                                        <span class="doc-file-type">
                                            {{ $doc->doc_type }}
                                            @if ($hit)<span class="doc-sr">(matches your search)</span>@endif
                                        </span>
                                        <span class="doc-file-name">{{ $doc->original_name }}</span>
                                        @unless ($doc->exists())
                                            <span class="doc-missing" title="The row exists but the stored file does not">File missing</span>
                                        @endunless
                                    </div>
                                    <span class="doc-file-size">{{ $size((int) $doc->size_bytes) }}</span>
                                    <time class="doc-file-date" datetime="{{ $doc->created_at->toDateString() }}">{{ $doc->created_at->format('j M Y') }}</time>
                                    <span class="doc-file-actions">
                                        {{-- The viewer and a download are real navigations,
                                             so RegionSwap leaves them alone. --}}
                                        <x-core::doc-link :document="$doc" class="doc-file-view" data-region-swap-ignore
                                                          aria-label="View {{ $doc->original_name }}">View</x-core::doc-link>
                                        <x-core::doc-link :document="$doc" action="download" class="doc-download"
                                                          data-region-swap-ignore aria-label="Download {{ $doc->original_name }}">
                                            @include('core::dashboard.partials.icon', ['name' => 'download'])
                                        </x-core::doc-link>
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        <p class="doc-group-foot">
                            <a href="{{ route('applications.show', $app) }}" data-region-swap-ignore>Open application</a>
                        </p>
                    </details>
                @endforeach
            </div>

            {{-- Hand-rolled like the notifications pager: Laravel's default
                 pagination views are Tailwind markup and this app has none. --}}
            @if ($groups->hasPages())
                <nav class="notif-pager" aria-label="Pagination">
                    @if ($groups->onFirstPage())
                        <span class="notif-page-link is-disabled">Previous</span>
                    @else
                        <a href="{{ $groups->previousPageUrl() }}" class="notif-page-link" rel="prev">Previous</a>
                    @endif

                    <span class="notif-page-count">
                        Page {{ number_format($groups->currentPage()) }} of {{ number_format($groups->lastPage()) }}
                    </span>

                    @if ($groups->hasMorePages())
                        <a href="{{ $groups->nextPageUrl() }}" class="notif-page-link" rel="next">Next</a>
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
