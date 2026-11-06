@extends('core::layouts.app')

@section('title', 'Appeal Candidature Termination')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Appeal Candidature Termination — {{ $application->reference() }}</h2>
        <div class="card-divider"></div>

        @if ($pending)
            <p class="queue-meta">
                You already have an appeal for this application pending a Senior Director's
                decision. You'll be notified once it's decided.
            </p>
        @else
            <p class="queue-meta">
                Your candidature on this appointment was terminated after a Stage Gate
                requirement's grace period lapsed without completion being recorded. Upload a
                letter explaining your appeal — it goes to CGS, your Supervisor and Admin, and
                only a Senior Director can approve or reject it.
            </p>

            <form method="POST" action="{{ route('candidature-appeal.store', $application) }}" enctype="multipart/form-data">
                @csrf

                <label for="appeal_letter">Appeal Letter</label>
                <input type="file" name="appeal_letter" id="appeal_letter" required>
                @error('appeal_letter') <p class="field-error">{{ $message }}</p> @enderror

                <button type="submit" data-confirm="Submit this appeal? CGS, your Supervisor and Admin will be notified.">Submit Appeal</button>
            </form>
            @include('haziq::partials.confirm_scripts')
        @endif
    </div>
</div>
@endsection
