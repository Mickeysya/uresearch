@extends('core::layouts.app')

@section('title', 'Update Supporting Document')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Update Supporting Document" />

    <div class="card card-wide">
        <p>Application #{{ $application->id }}</p>
        <p>Destination: {{ $detail->destination_address }}</p>
        <p>Dates: {{ $detail->travel_start_date->format('j M Y') }}
           to {{ $detail->travel_end_date->format('j M Y') }}</p>

        @if ($currentDocument)
            <p>Current file: <x-core::doc-link :document="$currentDocument">{{ $currentDocument->original_name }}</x-core::doc-link>
               · <x-core::doc-link :document="$currentDocument" action="download">Download</x-core::doc-link></p>
        @else
            <p style="color: var(--text-grey);">No supporting document uploaded yet.</p>
        @endif

        <form method="POST" action="{{ route('travel.document.update', $application) }}"
              enctype="multipart/form-data" class="app-form">
            @csrf

            <label for="supporting_document">Supporting Document</label>
            <input type="file" name="supporting_document" id="supporting_document" required
                   accept=".pdf,.jpg,.jpeg,.png"
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">PDF, JPG or PNG, up to 5 MB. Replaces the current file.</p>

            <button type="submit">Upload</button>
        </form>
    </div>
</div>
@include('norhanis::partials.file-remove')
@endsection
