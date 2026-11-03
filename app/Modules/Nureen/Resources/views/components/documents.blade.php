@props([
    // Rows from Support\DocumentPreviews: label, name, kind, view, download, note.
    'items' => [],
    'title' => 'Documents',
])

{{--
    Every attachment and generated document of one application, each with
    View (the preview overlay, components/file-preview) and Download. View
    is a real link to the inline route, so without JavaScript it opens the
    file in a new tab; with it, the overlay intercepts the click.
--}}
<section class="nureen-docs" aria-label="{{ $title }}">
    <p class="nureen-docs-title">{{ $title }}</p>
    @if ($items === [])
        <p class="queue-meta">No documents attached.</p>
    @else
        <ul class="nureen-docs-list">
            @foreach ($items as $item)
                <li class="nureen-docs-item">
                    <span class="nureen-docs-name">
                        <span class="nureen-docs-label">{{ $item['label'] }}</span>
                        <span class="queue-meta">{{ $item['name'] }}</span>
                        @if ($item['note'])
                            <span class="queue-meta nureen-docs-note">{{ $item['note'] }}</span>
                        @endif
                    </span>
                    <span class="nureen-docs-actions">
                        <a href="{{ $item['view'] }}" target="_blank" rel="noopener" class="btn-secondary"
                           data-preview-url="{{ $item['view'] }}" data-preview-kind="{{ $item['kind'] }}"
                           data-preview-name="{{ $item['name'] }}" data-preview-download="{{ $item['download'] }}"
                           aria-label="View {{ $item['label'] }}: {{ $item['name'] }}">View</a>
                        <a href="{{ $item['download'] }}" class="btn-secondary"
                           aria-label="Download {{ $item['label'] }}: {{ $item['name'] }}">Download</a>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@once
    <style>
        .nureen-docs { margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border-subtle); }
        .nureen-docs-title { margin: 0 0 var(--space-2); font-weight: var(--weight-semi); color: var(--text-dark); }
        .nureen-docs-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
        .nureen-docs-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-4); padding: var(--space-2) 0; border-bottom: 1px solid var(--border-subtle); }
        .nureen-docs-item:last-child { border-bottom: 0; }
        .nureen-docs-name { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
        .nureen-docs-label { color: var(--text-dark); }
        .nureen-docs-actions { display: flex; gap: var(--space-2); flex-shrink: 0; }
        .nureen-docs-actions .btn-secondary { min-height: 36px; padding: 6px 14px; font-size: var(--text-sm); }
        .nureen-docs-actions a:focus-visible { outline: 3px solid var(--navy); outline-offset: 2px; }
    </style>
    <x-nureen::file-preview />
@endonce
