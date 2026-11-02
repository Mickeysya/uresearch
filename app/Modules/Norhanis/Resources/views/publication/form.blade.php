@extends('core::layouts.app')

@section('title', 'New Publication Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Publication Funding Application" />

    @foreach ($awaitingSignOffs as $waiting)
        <p class="message-success">
            Publication Application #{{ $waiting->id }} is waiting for sign-offs from your co-authors and reviewers.
            <a href="{{ route('publication.sign-offs', $waiting) }}">See who has signed</a>
        </p>
    @endforeach

    <div class="card card-wide">
        <form method="POST" action="{{ route('publication.store') }}" enctype="multipart/form-data" class="app-form" data-stepper
              data-stepper-review="Check everything below, then submit. Your co-authors and your reviewers are each emailed a link. The application goes to your supervisor once all of them have signed. You cannot edit it after this.">
            @csrf

            <fieldset class="fstep" data-label="Paper">
            <p class="fstep-hint">Nothing here is downloaded, printed or signed by hand. The Authorship Certification and the Paper Evaluation Sheet are both completed online: you name the people on the next steps, and each is emailed a link to sign.</p>

            <label for="type_of_request">Type of Request</label>
            <select name="type_of_request" id="type_of_request" required
                    class="@error('type_of_request') is-invalid @enderror">
                <option value="">-- Select --</option>
                @foreach ($requestTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('type_of_request') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type_of_request') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Conference only. It sets how many internal reviewers must
                 complete a Paper Evaluation Sheet, and so how many reviewers
                 the Reviewers step asks for. --}}
            <label for="conference_scope" data-conference-only>Local or International Conference</label>
            <select name="conference_scope" id="conference_scope" data-conference-only
                    class="@error('conference_scope') is-invalid @enderror">
                <option value="">-- Select --</option>
                @foreach ($conferenceScopes as $value => $label)
                    <option value="{{ $value }}" @selected(old('conference_scope') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('conference_scope') <p class="field-error">{{ $message }}</p> @enderror

            <label for="title_of_paper">Title of Paper</label>
            <input type="text" name="title_of_paper" id="title_of_paper" required
                   value="{{ old('title_of_paper') }}"
                   class="@error('title_of_paper') is-invalid @enderror">
            @error('title_of_paper') <p class="field-error">{{ $message }}</p> @enderror

            <label for="title_of_conference_journal">Title of Conference / Journal</label>
            <input type="text" name="title_of_conference_journal" id="title_of_conference_journal" required
                   value="{{ old('title_of_conference_journal') }}"
                   class="@error('title_of_conference_journal') is-invalid @enderror">
            @error('title_of_conference_journal') <p class="field-error">{{ $message }}</p> @enderror

            <label for="organizer_publisher">Organizer / Publisher</label>
            <input type="text" name="organizer_publisher" id="organizer_publisher" required
                   value="{{ old('organizer_publisher') }}"
                   class="@error('organizer_publisher') is-invalid @enderror">
            @error('organizer_publisher') <p class="field-error">{{ $message }}</p> @enderror

            <label for="venue">Venue <span style="color: var(--text-grey)">(conference only, e.g. the city and hall)</span></label>
            <input type="text" name="venue" id="venue" value="{{ old('venue') }}"
                   class="@error('venue') is-invalid @enderror">
            @error('venue') <p class="field-error">{{ $message }}</p> @enderror

            <label for="conference_start_date">Conference / Publication Start Date</label>
            <input type="date" name="conference_start_date" id="conference_start_date" required
                   value="{{ old('conference_start_date') }}"
                   class="@error('conference_start_date') is-invalid @enderror">
            @error('conference_start_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="conference_end_date">Conference / Publication End Date</label>
            <input type="date" name="conference_end_date" id="conference_end_date" required
                   value="{{ old('conference_end_date') }}"
                   class="@error('conference_end_date') is-invalid @enderror">
            @error('conference_end_date') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <fieldset class="fstep" data-label="Cost">
            <label for="conference_journal_fee" style="margin-top: 0;">Conference / Journal Fee</label>
            <input type="number" step="0.01" name="conference_journal_fee" id="conference_journal_fee" required
                   value="{{ old('conference_journal_fee', 0) }}"
                   class="@error('conference_journal_fee') is-invalid @enderror">
            @error('conference_journal_fee') <p class="field-error">{{ $message }}</p> @enderror

            <label for="currency_type">Currency</label>
            <select name="currency_type" id="currency_type" required
                    class="@error('currency_type') is-invalid @enderror">
                @foreach (['MYR', 'USD', 'SGD', 'GBP', 'EUR'] as $currency)
                    <option value="{{ $currency }}" @selected(old('currency_type', 'MYR') === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
            @error('currency_type') <p class="field-error">{{ $message }}</p> @enderror

            {{-- One choice, as agreed with CGS. A Letter of Undertaking is one of
                 them, so there is no separate tick box. A split memo is not done
                 in UResearch: CGS emails the template and the supervisor fills it. --}}
            <label for="cost_centre">Cost Centre to be Utilized</label>
            <select name="cost_centre" id="cost_centre" required
                    class="@error('cost_centre') is-invalid @enderror">
                <option value="">-- Select --</option>
                @foreach ($costCentres as $value => $labelText)
                    <option value="{{ $value }}" @selected(old('cost_centre') === $value)>{{ $labelText }}</option>
                @endforeach
            </select>
            @error('cost_centre') <p class="field-error">{{ $message }}</p> @enderror

            <p class="field-hint" data-cost-split @if (old('cost_centre') !== 'split_memo') hidden @endif>
                CGS will email you the split memo template. Your supervisor completes it outside UResearch.
            </p>

            <label for="cost_centre_other" data-cost-others @if (old('cost_centre') !== 'others') hidden @endif>If "Others", please specify</label>
            <input type="text" name="cost_centre_other" id="cost_centre_other" maxlength="150" data-cost-others
                   value="{{ old('cost_centre_other') }}"
                   @if (old('cost_centre') !== 'others') hidden disabled @endif
                   class="@error('cost_centre_other') is-invalid @enderror">
            @error('cost_centre_other') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <fieldset class="fstep" data-label="Authors">
            <p class="fstep-hint">Everyone credited on the paper, in the order listed on the manuscript, yourself included. Each co-author is emailed a link to sign the Authorship Certification online, so nobody prints or uploads it.</p>

            @error('authors') <p class="field-error">{{ $message }}</p> @enderror

            <div id="authors-container">
                <div class="pub-person-row" data-index="0">
                    <p class="queue-meta">Author 1</p>

                    <label for="author_0_name"><span hidden>Author 1 </span>Name</label>
                    <input type="text" name="authors[0][author_name]" id="author_0_name" value="{{ old('authors.0.author_name', auth()->user()->name) }}">
                    @error('authors.0.author_name') <p class="field-error">{{ $message }}</p> @enderror

                    <label for="author_0_email"><span hidden>Author 1 </span>Email</label>
                    <input type="email" name="authors[0][email]" id="author_0_email" required value="{{ old('authors.0.email', auth()->user()->email) }}">

                    <label for="author_0_designation"><span hidden>Author 1 </span>Designation</label>
                    <input type="text" name="authors[0][designation]" id="author_0_designation" value="{{ old('authors.0.designation') }}"
                           placeholder="e.g. PhD Student, Co-Supervisor, External Collaborator">

                    <label for="author_0_organisation"><span hidden>Author 1 </span>Organisation</label>
                    <input type="text" name="authors[0][organisation]" id="author_0_organisation" value="{{ old('authors.0.organisation') }}">

                    <label for="author_0_role"><span hidden>Author 1 </span>Role / Contribution</label>
                    <input type="text" name="authors[0][role_contribution]" id="author_0_role" value="{{ old('authors.0.role_contribution') }}">
                </div>
            </div>

            <button type="button" id="add-author-btn" class="btn-secondary" style="margin-top: 12px;">
                + Add Another Author
            </button>

            @error('authors.*.email') <p class="field-error">{{ $message }}</p> @enderror

            {{-- The student's own line of the Authorship Certification Form.
                 Their co-authors sign the same statement from an emailed
                 link; see PublicationCertificationController. --}}
            <p style="margin-top: 20px;"><b>Your certification</b></p>
            <p class="fstep-hint">Every listed author has made a substantial contribution to the conception and design, or analysis and interpretation of data; drafted the article or revised it critically; and approved the final version. No part of the work has been published before or is under consideration elsewhere.</p>

            <label for="applicant_conflict_disclosure">Conflict of interest to disclose <span style="color: var(--text-grey)">(leave blank if none)</span></label>
            <textarea name="applicant_conflict_disclosure" id="applicant_conflict_disclosure" rows="2"
                      class="@error('applicant_conflict_disclosure') is-invalid @enderror">{{ old('applicant_conflict_disclosure') }}</textarea>
            @error('applicant_conflict_disclosure') <p class="field-error">{{ $message }}</p> @enderror

            <label>Your signature</label>
            @include('norhanis::publication._signature_pad', ['name' => 'applicant_signature'])

            <div class="checkbox-row" style="margin-top: 12px;">
                <input type="checkbox" name="applicant_certifies" id="applicant_certifies" value="1" required>
                <label for="applicant_certifies">I certify that the work submitted is in full accordance with the Authorship Policy of Universiti Teknologi PETRONAS, and I agree on the author order listed above.</label>
            </div>
            @error('applicant_certifies') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <fieldset class="fstep" data-label="Reviewers">
            <p class="fstep-hint">Your internal reviewers: two for a journal or a local conference, three for an international conference. Each is emailed a link to read the paper and complete the Paper Evaluation Sheet online, so nobody prints or uploads it. A reviewer must be UTP staff and cannot be one of the authors.</p>

            <label for="manuscript" style="margin-top: 0;">Paper for the reviewers to read</label>
            <input type="file" name="manuscript" id="manuscript" required class="@error('manuscript') is-invalid @enderror">
            @error('manuscript') <p class="field-error">{{ $message }}</p> @enderror

            @error('reviewers') <p class="field-error">{{ $message }}</p> @enderror
            @error('reviewers.*.name') <p class="field-error">{{ $message }}</p> @enderror
            @error('reviewers.*.email') <p class="field-error">{{ $message }}</p> @enderror

            {{-- The third reviewer is switched on by the script below, only
                 for an international conference; the controller enforces the
                 same count. --}}
            @foreach ([0, 1, 2] as $index)
                <div class="pub-person-row" @if ($index === 2) data-third-reviewer @endif>
                    <p class="queue-meta">Reviewer {{ $index + 1 }}@if ($index === 2) (international conference only) @endif</p>

                    <label for="reviewer_name_{{ $index }}">Name</label>
                    <input type="text" name="reviewers[{{ $index }}][name]" id="reviewer_name_{{ $index }}"
                           value="{{ old("reviewers.$index.name") }}"
                           @if ($index === 2) data-third-reviewer @else required @endif>

                    <label for="reviewer_email_{{ $index }}">UTP email</label>
                    <input type="email" name="reviewers[{{ $index }}][email]" id="reviewer_email_{{ $index }}"
                           value="{{ old("reviewers.$index.email") }}" placeholder="name@utp.edu.my"
                           @if ($index === 2) data-third-reviewer @else required @endif>
                </div>
            @endforeach
            </fieldset>

            <fieldset class="fstep" data-label="Documents">
            <label for="invoice" style="margin-top: 0;">Invoice</label>
            <input type="file" name="invoice" id="invoice" required class="@error('invoice') is-invalid @enderror">
            @error('invoice') <p class="field-error">{{ $message }}</p> @enderror

            <label for="index_proof">Index Proof</label>
            <input type="file" name="index_proof" id="index_proof" required class="@error('index_proof') is-invalid @enderror">
            @error('index_proof') <p class="field-error">{{ $message }}</p> @enderror

            <label for="turnitin_report">Turnitin Report</label>
            <input type="file" name="turnitin_report" id="turnitin_report" required class="@error('turnitin_report') is-invalid @enderror">
            @error('turnitin_report') <p class="field-error">{{ $message }}</p> @enderror

            <label for="letter_of_acceptance">Letter of Acceptance</label>
            <input type="file" name="letter_of_acceptance" id="letter_of_acceptance" required class="@error('letter_of_acceptance') is-invalid @enderror">
            @error('letter_of_acceptance') <p class="field-error">{{ $message }}</p> @enderror

            <label for="conference_journal_details">Conference/Journal Details</label>
            <input type="file" name="conference_journal_details" id="conference_journal_details" required class="@error('conference_journal_details') is-invalid @enderror">
            @error('conference_journal_details') <p class="field-error">{{ $message }}</p> @enderror

            <label for="response_to_reviewer_comment">Response to Reviewer Comment</label>
            <input type="file" name="response_to_reviewer_comment" id="response_to_reviewer_comment" required class="@error('response_to_reviewer_comment') is-invalid @enderror">
            @error('response_to_reviewer_comment') <p class="field-error">{{ $message }}</p> @enderror

            <label for="corrected_conference_journal_paper">Corrected Conference/Journal Paper</label>
            <input type="file" name="corrected_conference_journal_paper" id="corrected_conference_journal_paper" required class="@error('corrected_conference_journal_paper') is-invalid @enderror">
            @error('corrected_conference_journal_paper') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')

{{-- Each author and reviewer is its own card. Without it, the rows ran into
     each other: .queue-meta pulls itself up with a negative top margin, so
     "Author 2" sat on top of Author 1's last field. --}}
<style>
    .pub-person-row {
        max-width: 46rem;
        margin-top: var(--space-4);
        padding: var(--space-4) var(--space-5) var(--space-5);
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-md);
        background: var(--surface-raised);
    }

    .pub-person-row > .queue-meta {
        margin: 0;
        color: var(--text-dark);
        font-weight: var(--weight-semi);
    }

    .pub-person-row > label:first-of-type {
        margin-top: var(--space-3);
    }

    .pub-person-row[hidden] {
        display: none;
    }
</style>

<template id="author-row-template">
    <div class="pub-person-row" data-index="__INDEX__">
        <p class="queue-meta">Author __LABEL__</p>
        <label for="author___INDEX___name"><span hidden>Author __LABEL__ </span>Name</label>
        <input type="text" name="authors[__INDEX__][author_name]" id="author___INDEX___name">
        <label for="author___INDEX___email"><span hidden>Author __LABEL__ </span>Email</label>
        <input type="email" name="authors[__INDEX__][email]" id="author___INDEX___email" required>
        <label for="author___INDEX___designation"><span hidden>Author __LABEL__ </span>Designation</label>
        <input type="text" name="authors[__INDEX__][designation]" id="author___INDEX___designation" placeholder="e.g. PhD Student, Co-Supervisor, External Collaborator">
        <label for="author___INDEX___organisation"><span hidden>Author __LABEL__ </span>Organisation</label>
        <input type="text" name="authors[__INDEX__][organisation]" id="author___INDEX___organisation">
        <label for="author___INDEX___role"><span hidden>Author __LABEL__ </span>Role / Contribution</label>
        <input type="text" name="authors[__INDEX__][role_contribution]" id="author___INDEX___role">
        <button type="button" class="btn-secondary remove-author-btn" style="margin-top: 8px;">Remove</button>
    </div>
</template>

<script @cspNonce>
    let authorIndex = 1;
    document.getElementById('add-author-btn').addEventListener('click', function () {
        const template = document.getElementById('author-row-template').innerHTML;
        const html = template
            .replaceAll('__INDEX__', authorIndex)
            .replaceAll('__LABEL__', authorIndex + 1);
        document.getElementById('authors-container').insertAdjacentHTML('beforeend', html);
        authorIndex++;
        syncDocuments();
    });

    document.getElementById('authors-container').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-author-btn')) {
            e.target.closest('.pub-person-row').remove();
            syncDocuments();
        }
    });

    // Mirrors what PublicationController::store() enforces, so the student
    // is told on the step itself rather than after a round trip: the scope
    // question only for a conference, a third reviewer only for an
    // international one, and the "please specify" box only for an "Others"
    // cost centre. A switched-off control is disabled as well as
    // hidden, so it is neither validated by the browser nor submitted.
    const typeOfRequest = document.getElementById('type_of_request');
    const conferenceScope = document.getElementById('conference_scope');
    const costCentre = document.getElementById('cost_centre');

    function toggle(selector, on) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.hidden = ! on;
            if (el.matches('input, select')) {
                el.disabled = ! on;
                el.required = on;
            }
        });
    }

    function syncDocuments() {
        const isConference = typeOfRequest.value === 'publication_conference';

        toggle('[data-conference-only]', isConference);
        toggle('[data-third-reviewer]', isConference && conferenceScope.value === 'international');
    }

    function syncCostCentre() {
        toggle('[data-cost-others]', costCentre.value === 'others');
        toggle('[data-cost-split]', costCentre.value === 'split_memo');
    }

    costCentre.addEventListener('change', syncCostCentre);

    // The signature lives in a hidden field, which the stepper's per-step
    // check skips. Hanging the message on the certify box instead means
    // Continue stops here, with a reason, until the box above is signed.
    const certifies = document.getElementById('applicant_certifies');
    certifies.setCustomValidity('Sign in the box above first.');
    document.addEventListener('signature:change', function (e) {
        certifies.setCustomValidity(e.detail.signed ? '' : 'Sign in the box above first.');
    });

    typeOfRequest.addEventListener('change', syncDocuments);
    conferenceScope.addEventListener('change', syncDocuments);
    syncDocuments();
</script>
@include('norhanis::partials.file-remove')
@endsection
