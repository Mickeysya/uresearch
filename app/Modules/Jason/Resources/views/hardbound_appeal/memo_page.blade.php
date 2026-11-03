@extends('core::layouts.app')

@section('title', 'Extension memo: #' . $application->id)

@section('content')
@push('head')
    <style>
        .pdf-frame {
            width: 100%;
            height: 78vh;
            min-height: 560px;
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
        title="Appeal for Extension #{{ $application->id }}"
        subtitle="{{ $student?->name }}{{ $student?->matric_no ? ' · '.$student->matric_no : '' }}{{ $detail?->requested_until ? ' · until '.$detail->requested_until->format('j F Y') : '' }}" />

    <p class="queue-meta">
        The memo is written by the portal from what the candidate entered and the signatures
        collected so far, and is not stored anywhere, so this page draws it fresh. Endorsing
        adds your signature to it.
    </p>

    <p style="margin-bottom: var(--space-4);">
        <a href="{{ route('hardbound-appeal.memo', [$application, 'download' => 1]) }}"
           class="btn-secondary">Download the memo</a>
    </p>

    <iframe class="pdf-frame"
            src="{{ route('hardbound-appeal.memo', $application) }}"
            title="Extension memo for appeal {{ $application->id }}"></iframe>

    <p class="field-hint">
        The Dean's <b>Approved / Not Approved</b> block is left blank: CGS takes the memo to the
        Dean off-portal and emails the candidate the outcome.
    </p>
</div>
@endsection
