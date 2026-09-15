@extends('core::layouts.app')

@section('title', 'New Publication Application')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Publication Funding Application</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('publication.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Paper">
            <p class="fstep-hint">The paper itself and where it is being presented or published.</p>

            
            <label for="type_of_request">Type of Request</label>
            <select name="type_of_request" id="type_of_request" required
                    class="@error('type_of_request') is-invalid @enderror">
                <option value="">-- Select --</option>
                @foreach ($requestTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('type_of_request') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type_of_request') <p class="field-error">{{ $message }}</p> @enderror

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
            <p class="fstep-hint">What it costs, in which currency, and which cost centre it is charged against.</p>

            <label for="conference_journal_fee">Conference / Journal Fee</label>
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

            <label for="cost_centre">Cost Centre</label>
            <input type="text" name="cost_centre" id="cost_centre" required
                   value="{{ old('cost_centre') }}"
                   class="@error('cost_centre') is-invalid @enderror">
            @error('cost_centre') <p class="field-error">{{ $message }}</p> @enderror

            <div class="checkbox-row" style="margin-top: 12px;">
                <input type="checkbox" name="wants_letter_of_undertaking" id="wants_letter_of_undertaking" value="1"
                       @checked(old('wants_letter_of_undertaking'))>
                <label for="wants_letter_of_undertaking">I require a Letter of Undertaking</label>
            </div>

            </fieldset>

            <fieldset class="fstep" data-label="Authors">
            <p class="fstep-hint">Everyone credited on the paper. List more than five and the Authorship Contribution Form becomes mandatory on the next step.</p>

            @error('authors') <p class="field-error">{{ $message }}</p> @enderror

            <div id="authors-container">
                <div class="claim-item-row" data-index="0">
                    <p class="queue-meta">Author 1</p>

                    <label>Name</label>
                    <input type="text" name="authors[0][author_name]" value="{{ old('authors.0.author_name') }}">
                    @error('authors.0.author_name') <p class="field-error">{{ $message }}</p> @enderror

                    <label>Designation</label>
                    <input type="text" name="authors[0][designation]" value="{{ old('authors.0.designation') }}"
                           placeholder="e.g. PhD Student, Co-Supervisor, External Collaborator">

                    <label>Organisation</label>
                    <input type="text" name="authors[0][organisation]" value="{{ old('authors.0.organisation') }}">

                    <label>Role / Contribution</label>
                    <input type="text" name="authors[0][role_contribution]" value="{{ old('authors.0.role_contribution') }}">
                </div>
            </div>

            <button type="button" id="add-author-btn" class="btn-secondary" style="margin-top: 12px;">
                + Add Another Author
            </button>

            </fieldset>

            <fieldset class="fstep" data-label="Documents">
            <p class="fstep-hint">All optional unless marked otherwise. Each is attached under its own label so CGS can tell them apart later.</p>

            
            <label for="invoice">Invoice <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="invoice" id="invoice" class="@error('invoice') is-invalid @enderror">
            @error('invoice') <p class="field-error">{{ $message }}</p> @enderror

            <label for="index_proof">Index Proof <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="index_proof" id="index_proof" class="@error('index_proof') is-invalid @enderror">
            @error('index_proof') <p class="field-error">{{ $message }}</p> @enderror

            <label for="turnitin_report">Turnitin Report <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="turnitin_report" id="turnitin_report" class="@error('turnitin_report') is-invalid @enderror">
            @error('turnitin_report') <p class="field-error">{{ $message }}</p> @enderror

            <label for="letter_of_acceptance">Letter of Acceptance <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="letter_of_acceptance" id="letter_of_acceptance" class="@error('letter_of_acceptance') is-invalid @enderror">
            @error('letter_of_acceptance') <p class="field-error">{{ $message }}</p> @enderror

            <label for="conference_journal_details">Conference/Journal Details <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="conference_journal_details" id="conference_journal_details" class="@error('conference_journal_details') is-invalid @enderror">
            @error('conference_journal_details') <p class="field-error">{{ $message }}</p> @enderror

            <label for="authorship_certification_form">Authorship Certification Form <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="authorship_certification_form" id="authorship_certification_form" class="@error('authorship_certification_form') is-invalid @enderror">
            @error('authorship_certification_form') <p class="field-error">{{ $message }}</p> @enderror

            <label for="response_to_reviewer_comment">Response to Reviewer Comment <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="response_to_reviewer_comment" id="response_to_reviewer_comment" class="@error('response_to_reviewer_comment') is-invalid @enderror">
            @error('response_to_reviewer_comment') <p class="field-error">{{ $message }}</p> @enderror

            <label for="approved_memo_for_utilizing_grant">Approved Memo for Utilizing Grant <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="approved_memo_for_utilizing_grant" id="approved_memo_for_utilizing_grant" class="@error('approved_memo_for_utilizing_grant') is-invalid @enderror">
            @error('approved_memo_for_utilizing_grant') <p class="field-error">{{ $message }}</p> @enderror

            <label for="corrected_conference_journal_paper">Corrected Conference/Journal Paper <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="corrected_conference_journal_paper" id="corrected_conference_journal_paper" class="@error('corrected_conference_journal_paper') is-invalid @enderror">
            @error('corrected_conference_journal_paper') <p class="field-error">{{ $message }}</p> @enderror

            <label for="authorship_contribution_form">Authorship Contribution Form
                <span style="color: var(--text-grey)">(required only if more than 5 authors are listed above)</span></label>
            <input type="file" name="authorship_contribution_form" id="authorship_contribution_form" class="@error('authorship_contribution_form') is-invalid @enderror">
            @error('authorship_contribution_form') <p class="field-error">{{ $message }}</p> @enderror

            <label for="paper_evaluation_sheet">Paper Evaluation Sheet <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="paper_evaluation_sheet" id="paper_evaluation_sheet" class="@error('paper_evaluation_sheet') is-invalid @enderror">
            @error('paper_evaluation_sheet') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')

<template id="author-row-template">
    <div class="claim-item-row" data-index="__INDEX__">
        <p class="queue-meta">Author __LABEL__</p>
        <label>Name</label>
        <input type="text" name="authors[__INDEX__][author_name]">
        <label>Designation</label>
        <input type="text" name="authors[__INDEX__][designation]" placeholder="e.g. PhD Student, Co-Supervisor, External Collaborator">
        <label>Organisation</label>
        <input type="text" name="authors[__INDEX__][organisation]">
        <label>Role / Contribution</label>
        <input type="text" name="authors[__INDEX__][role_contribution]">
        <button type="button" class="btn-secondary remove-author-btn" style="margin-top: 8px;">Remove</button>
    </div>
</template>

<script @cspNonce>
    let authorIndex = 1;
    document.getElementById('add-author-btn').addEventListener('click', function () {
        const template = document.getElementById('author-row-template').innerHTML;
        const html = template
            .replaceAll('__INDEX__', authorIndex)
            .replace('__LABEL__', authorIndex + 1);
        document.getElementById('authors-container').insertAdjacentHTML('beforeend', html);
        authorIndex++;
    });

    document.getElementById('authors-container').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-author-btn')) {
            e.target.closest('.claim-item-row').remove();
        }
    });
</script>
@endsection
