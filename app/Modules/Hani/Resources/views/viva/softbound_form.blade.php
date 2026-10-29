@extends('core::layouts.app')

@section('title', 'Softbound Thesis Submission')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Softbound Thesis Submission"
        subtitle="CGS Online Form #019. Get your thesis signed by yourself, your supervisor, co-supervisor and chair before submitting. Your Academic Executive checks it against the details below." />

    <div class="card card-wide">
        @if ($returnReason)
            <p class="message-warning">Your last submission was returned: {{ $returnReason }}</p>
        @endif

        <form method="POST" action="{{ route('viva.softbound.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="similarity_percent">Similarity Index (%)</label>
            <input type="number" name="similarity_percent" id="similarity_percent" required
                   min="0" max="{{ $similarityMax }}" step="0.01"
                   value="{{ old('similarity_percent') }}"
                   class="@error('similarity_percent') is-invalid @enderror">
            <p class="fstep-hint">From your similarity report. {{ $similarityMax }}% or below.</p>
            @error('similarity_percent') <p class="field-error">{{ $message }}</p> @enderror

            <label for="similarity_report">Similarity Report</label>
            <input type="file" name="similarity_report" id="similarity_report" required
                   accept="application/pdf,.pdf"
                   class="@error('similarity_report') is-invalid @enderror">
            <p class="fstep-hint">PDF, up to 5 MB.</p>
            @error('similarity_report') <p class="field-error">{{ $message }}</p> @enderror

            <label for="word_count">Word Count</label>
            <input type="number" name="word_count" id="word_count" required
                   min="1" max="{{ $wordLimit }}" step="1"
                   value="{{ old('word_count') }}"
                   class="@error('word_count') is-invalid @enderror">
            <p class="fstep-hint">{{ number_format($wordLimit) }} words or fewer for your programme.</p>
            @error('word_count') <p class="field-error">{{ $message }}</p> @enderror

            <label for="thesis">Softbound Thesis</label>
            <input type="file" name="thesis" id="thesis" required
                   accept="application/pdf,.pdf"
                   class="@error('thesis') is-invalid @enderror">
            <p class="fstep-hint">PDF, up to 20 MB.</p>
            @error('thesis') <p class="field-error">{{ $message }}</p> @enderror

            @foreach ([
                'abstract_bilingual' => 'The abstract is in both English and Bahasa Melayu',
                'copies_certified' => 'Two copies are certified by my supervisor',
                'before_candidacy_end' => 'I am submitting before my candidacy ends',
            ] as $field => $text)
                <div class="checkbox-row">
                    <input type="checkbox" name="{{ $field }}" id="{{ $field }}" value="1" required
                           @checked(old($field))>
                    <label for="{{ $field }}">{{ $text }}</label>
                </div>
                @error($field) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach

            <button type="submit">Submit Softbound Thesis</button>
        </form>
    </div>
</div>
@endsection
