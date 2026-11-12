@extends('core::layouts.app')

@section('title', 'GA Scheme: Terms and Conditions')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Graduate Assistant (GA) Scheme: Terms and Conditions"
        subtitle="Read this before starting your GA application. You only need to accept this once; it covers every GA application you submit afterwards." />

    <div class="card card-wide">
        <div class="haziq-repeatable-row" style="grid-template-columns: 1fr; gap: 10px;">
            <p><b>Eligibility.</b> Open to full-time, on-campus postgraduate students in good
                academic standing, appointed to assist a department or unit with teaching or
                related duties.</p>

            <p><b>Interview.</b> Every application goes through an interview conducted by
                Research Centre before it can proceed to CGS. A failed interview ends the
                application outright; it does not reach CGS for a decision.</p>

            <p><b>Hours.</b> Proposed weekly hours are capped and reviewed by every approver in
                the chain before final approval.</p>

            <p><b>Certification.</b> By accepting below, you certify that the statements and
                information you are about to submit are true and correct, and you understand
                that falsification or material omission of information may result in your GA
                appointment being terminated.</p>
        </div>

        <form method="POST" action="{{ route('ga-application.terms.accept') }}" style="margin-top: 18px;">
            @csrf

            <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal;">
                <input type="checkbox" name="agree" value="1" required style="margin-top: 3px;">
                <span>I have read and agree to the GA Scheme Terms and Conditions above.</span>
            </label>
            @error('agree') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" style="margin-top: 12px;">Agree &amp; Continue</button>
        </form>
    </div>
</div>
@endsection
