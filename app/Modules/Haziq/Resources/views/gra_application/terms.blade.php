@extends('core::layouts.app')

@section('title', 'GRA Scheme — Terms and Conditions')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Graduate Research Assistant (GRA) Scheme — Terms and Conditions</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Read this before starting your {{ $intent === 'extension' ? 'extension request' : 'GRA application' }}.
            You only need to accept this once — it covers every GRA application and extension you submit afterwards.
        </p>

        <div class="haziq-repeatable-row" style="grid-template-columns: 1fr; gap: 10px;">
            <p><b>Eligibility.</b> Open to full-time, on-campus postgraduate students in good academic standing,
                appointed to assist a Project Leader on a funded research grant.</p>

            <p><b>Duration of appointment.</b> Counted from your admission date, total GRA funding may not exceed
                24 months for a Master's candidate or 42 months for a PhD candidate, across the original
                appointment and any extensions combined.</p>

            <p><b>Research Proposal Defence (RPD) / Stage Gate requirements.</b> Each Stage Gate milestone must be
                completed within the deadline and grace period set for your level of study. Allowance is withheld,
                not forfeited, while a milestone is overdue but still inside its grace period; missing the grace
                period as well suspends the appointment and the allowance for that period until the matter is
                resolved or appealed.</p>

            <p><b>Appointment dates.</b> A GRA appointment's start date must fall on the 1st or the 16th of a
                month, and its end date on the 15th or the last day of a month. Backdated appointments and
                redundant/duplicate agreements are not permitted.</p>

            <p><b>Payment.</b> Monthly work is claimed from the 16th of one month to the 15th of the next, and is
                paid once a month, for the preceding period's work.</p>

            <p><b>Certification.</b> By accepting below, you certify that the statements and information you are
                about to submit are true and correct, and you understand that falsification or material omission
                of information may result in your GRA contract being terminated.</p>
        </div>

        <form method="POST" action="{{ route('gra-application.terms.accept') }}" style="margin-top: 18px;">
            @csrf
            <input type="hidden" name="intent" value="{{ $intent }}">

            <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal;">
                <input type="checkbox" name="agree" value="1" required style="margin-top: 3px;">
                <span>I have read and agree to the GRA Scheme Terms and Conditions above.</span>
            </label>
            @error('agree') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" style="margin-top: 12px;">Agree &amp; Continue</button>
        </form>
    </div>
</div>
@endsection
