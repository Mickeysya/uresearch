@extends('core::layouts.app')

@section('title', 'Appointment pack: #' . $application->id)

@section('content')
@push('head')
    <style>
        .pack-layout { display: flex; gap: var(--space-5); align-items: flex-start; flex-wrap: wrap; }
        .pack-list { flex: 0 0 260px; min-width: 240px; margin: 0; padding: 0; list-style: none; }
        .pack-list li + li { margin-top: var(--space-2); }
        .pack-list a {
            display: block;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-dark);
            font-size: var(--text-sm);
        }
        .pack-list a.is-showing { border-color: var(--navy); background: var(--light-blue-bg); font-weight: var(--weight-semi); }
        .pack-list .kind { display: block; color: var(--text-grey); font-size: var(--text-xs); }

        .pack-view { flex: 1 1 520px; min-width: 0; }
        .pdf-frame {
            width: 100%;
            height: 76vh;
            min-height: 540px;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            background: var(--surface-sunken);
        }
    </style>
@endpush

<div class="card-container-inline">
    {{-- Above the header on purpose: below the frame it would be a scroll
         past a full page of PDF to find. --}}
    <p style="margin: 0 0 var(--space-3);">
        <a href="{{ $backTo }}">&larr; {{ $backLabel }}</a>
    </p>

    <x-core::page-header
        title="Appointment pack #{{ $application->id }}"
        subtitle="{{ $student?->name }}{{ $detail?->thesis_title ? ' · '.$detail->thesis_title : '' }}" />

    <p class="queue-meta">
        {{ $documents->count() }} {{ Str::plural('document', $documents->count()) }} prepared for
        this nomination: an appointment letter and a thesis evaluation report for each examiner.
        Pick one on the left to read it here, rather than downloading the set to check it.
    </p>

    <div class="pack-layout">
        <ul class="pack-list">
            @foreach ($documents as $document)
                <li>
                    <a href="{{ route('appointment-letter.pack', [$application, 'doc' => $document->id]) }}"
                       @class(['is-showing' => $document->id === $showing->id])>
                        {{ $document->original_name }}
                        <span class="kind">{{ $document->doc_type }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="pack-view">
            <p style="margin: 0 0 var(--space-3);">
                <a href="{{ route('appointment-letter.document', [$application, $showing, 'download' => 1]) }}"
                   class="btn-secondary">Download this one</a>
            </p>

            <iframe class="pdf-frame"
                    src="{{ route('appointment-letter.document', [$application, $showing]) }}"
                    title="{{ $showing->original_name }}"></iframe>
        </div>
    </div>
</div>
@endsection
