@extends('core::layouts.app')

@section('title', 'Submission forms: #' . $application->id)

@section('content')
@push('head')
    <style>
        /* Tall enough to read a full A4 page without the frame scrolling
           inside the page scrolling inside the frame. */
        .pdf-frame {
            width: 100%;
            height: 78vh;
            min-height: 560px;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            background: var(--surface-sunken);
        }

        .forms-actions { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-bottom: var(--space-4); }
    </style>
@endpush

<div class="card-container-inline">
    <x-core::page-header
        title="Hardbound Thesis Submission #{{ $application->id }}"
        subtitle="{{ $student?->name }}{{ $student?->matric_no ? ' · '.$student->matric_no : '' }}{{ $detail?->thesis_title ? ' · '.$detail->thesis_title : '' }}" />

    <p class="queue-meta">
        Both CGS forms are written by the portal from what the candidate entered, so there is
        nothing to download in order to read them. UTP/CGS/021 is below; the Confirmation of
        Correction is an archived document and opens through the usual download route, because
        it collects signatures as it moves.
    </p>

    <div class="forms-actions">
        <a href="{{ route('hardbound.submission-form', [$application, 'download' => 1]) }}"
           class="btn-secondary">Download UTP/CGS/021</a>

        @isset ($confirmation)
            <a href="{{ route('documents.show', $confirmation) }}" class="btn-secondary">
                Open the Confirmation of Correction
            </a>
        @endisset
    </div>

    <h3 style="margin: 0 0 var(--space-3);">Hardbound Thesis Submission (UTP/CGS/021)</h3>

    <iframe class="pdf-frame"
            src="{{ route('hardbound.submission-form', $application) }}"
            title="Hardbound Thesis Submission form for application {{ $application->id }}"></iframe>

    <p class="field-hint">
        ORIGINAL and STUDENT'S COPY, signed with the signature on the candidate's account. The
        FOR OFFICE USE ONLY block is left for CGS to sign and stamp when the CD and the two
        bound copies are handed over.
    </p>
</div>
@endsection
