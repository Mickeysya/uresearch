@extends('core::layouts.app')

@section('title', 'Application #' . $application->id)

@section('content')
    <h2>{{ $application->module()->label() }} Application #{{ $application->id }}</h2>
    <p class="queue-meta">
        <x-core::status-badge :status="$application->status" />
        &nbsp;Submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}
    </p>

    <div class="app-item">
        <p>{{ $application->module()->summary($application) }}</p>

        <x-core::stepper :application="$application" />

        @if ($rejection = $application->rejection())
            <div class="rejection-note">
                Rejected at {{ $rejection->stage_label }}.
                @if ($rejection->remarks) Remarks: {{ $rejection->remarks }} @endif
            </div>
        @endif

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        <div class="history-trail">
            @forelse ($application->history as $entry)
                <div class="entry">
                    <b>{{ $entry->stage_label }}</b> — {{ $entry->decision }}
                    by {{ $entry->approver->name }}
                    on {{ $entry->created_at->format('j M Y, g:ia') }}
                    @if ($entry->remarks) — "{{ $entry->remarks }}" @endif
                </div>
            @empty
                <div class="entry">No decisions recorded yet.</div>
            @endforelse
        </div>
    </div>

    <p><a href="{{ route('applications.index') }}">&larr; Back to all applications</a></p>
@endsection
