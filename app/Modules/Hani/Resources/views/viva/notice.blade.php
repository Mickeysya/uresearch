@extends('core::layouts.app')

@section('title', 'Notice of Thesis Submission')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Notice of Thesis Submission"
        subtitle="Submit this at least 3 months before your softbound thesis. Your Academic Executive will then contact your supervisor about appointing examiners." />

    @include('hani::partials.module_stepper', ['tracker' => $tracker])

    <div class="card card-wide">
        <p class="message-success">
            Eligibility checklist complete.
            <a href="{{ route('viva.eligibility') }}">Review it</a>.
        </p>

        <form method="POST" action="{{ route('viva.notice.store') }}" enctype="multipart/form-data" id="viva-notice">
            @csrf

            <label for="thesis_title">Thesis Title</label>
            <input type="text" name="thesis_title" id="thesis_title" required maxlength="255"
                   value="{{ old('thesis_title') }}"
                   class="@error('thesis_title') is-invalid @enderror">
            @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

            <label for="expected_softbound_date">Expected Softbound Submission Date</label>
            {{-- min mirrors the controller's 3-month rule so the browser
                 catches it before the server does. --}}
            <input type="date" name="expected_softbound_date" id="expected_softbound_date" required
                   min="{{ $earliestSoftbound->toDateString() }}"
                   value="{{ old('expected_softbound_date') }}"
                   class="@error('expected_softbound_date') is-invalid @enderror">
            <p class="fstep-hint">Earliest allowed: {{ $earliestSoftbound->format('j M Y') }}.</p>
            @error('expected_softbound_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="publication_proof">Publication Proof</label>
            <input type="file" name="publication_proof" id="publication_proof" required
                   accept="application/pdf,.pdf"
                   class="@error('publication_proof') is-invalid @enderror">
            <p class="fstep-hint">
                PDF, up to {{ $proofMaxMb }} MB. The acceptance letter or the published article.
                @if ($level === \App\Modules\Hani\Support\VivaChecklist::PHD)
                    If you are using two articles, combine them into one PDF.
                @endif
            </p>
            @error('publication_proof') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" data-submit>Submit Notice of Thesis Submission</button>
        </form>
    </div>
</div>

@push('scripts')
    <script @cspNonce>
        // Keeps the submit button locked until a PDF under the limit is
        // chosen. The controller validates the same thing.
        (function () {
            var form = document.getElementById('viva-notice');
            var input = document.getElementById('publication_proof');
            var button = form.querySelector('[data-submit]');
            var maxBytes = {{ (int) $proofMaxMb }} * 1024 * 1024;

            function sync() {
                var file = input.files[0];
                var ok = !!file && /\.pdf$/i.test(file.name) && file.size <= maxBytes;
                button.disabled = !ok;
                input.setCustomValidity(file && !ok ? 'Choose a PDF of {{ (int) $proofMaxMb }} MB or smaller.' : '');
            }

            input.addEventListener('change', function () { sync(); input.reportValidity(); });
            sync();
        })();
    </script>
@endpush
@endsection
