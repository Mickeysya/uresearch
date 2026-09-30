@extends('core::layouts.app')

@section('title', 'Import Examiner List')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Import Examiner List"
        subtitle="The finalised list, once the Dean has settled it. Each candidate on the sheet becomes an appointment waiting for its pack." />

    <div class="card card-wide">
        <p class="queue-meta">
            Examiner selection happens before this screen: the supervisor chooses the panel,
            the Academic Executive compiles it, and the list is agreed between CGS, the Senior
            Director, the Chair and the Dean. What you upload here is that finished list.
        </p>

        <p class="queue-meta">
            <b>One row per examiner</b>, not per candidate. A candidate's own columns repeat on
            each of their rows, and the rows are grouped on <code>matric_no</code>. Every panel
            needs at least one internal and one external examiner.
        </p>

        <form method="POST" action="{{ route('appointment-letter.import.store') }}"
              enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Get the template">
            <p class="fstep-hint">Only needed if you do not already have the list as a
               file. It comes filled in with two worked examples.</p>

            <a href="{{ route('appointment-letter.template') }}" class="btn-secondary">
                Download the template (.xlsx)
            </a>
            <p class="queue-meta">
                Or <a href="{{ route('appointment-letter.template', ['format' => 'csv']) }}">download it as .csv</a>.
                The first row must be exactly:
            </p>
            <p class="queue-meta" style="margin-top: -6px;">
                <code>{{ implode(', ', $columns) }}</code>
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Upload the list">
            <p class="fstep-hint">Nothing is imported unless every row is good, so a
               file with one bad row leaves you with no half-finished candidates to
               chase.</p>

            <label for="sheet">Finalised Examiner List</label>
            <input type="file" name="sheet" id="sheet" required
                   accept=".csv,.txt,.xlsx,.xls"
                   class="@error('sheet') is-invalid @enderror">
            @error('sheet') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">
                .xlsx or .csv, up to 5 MB. A candidate already imported gets a second
                appointment, so import each list once.
            </p>
            </fieldset>

            <button type="submit">Import the list</button>
        </form>

        @include('core::partials.form-stepper')
    </div>
</div>
@endsection
