@extends('core::layouts.app')

@section('title', 'New Publication Application')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Publication Funding Application</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('publication.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="conference_or_journal_name">Conference / Journal Name</label>
            <input type="text" name="conference_or_journal_name" id="conference_or_journal_name" required
                   value="{{ old('conference_or_journal_name') }}"
                   class="@error('conference_or_journal_name') is-invalid @enderror">
            @error('conference_or_journal_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="publication_title">Publication Title</label>
            <input type="text" name="publication_title" id="publication_title" required
                   value="{{ old('publication_title') }}"
                   class="@error('publication_title') is-invalid @enderror">
            @error('publication_title') <p class="field-error">{{ $message }}</p> @enderror

            <label for="event_date">Event / Publication Date</label>
            <input type="date" name="event_date" id="event_date" required
                   value="{{ old('event_date') }}"
                   class="@error('event_date') is-invalid @enderror">
            @error('event_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="location">Location / Country</label>
            <input type="text" name="location" id="location"
                   value="{{ old('location') }}">

            <label for="funding_amount_requested">Funding Amount Requested (RM)</label>
            <input type="number" step="0.01" name="funding_amount_requested" id="funding_amount_requested" required
                   value="{{ old('funding_amount_requested', 0) }}"
                   class="@error('funding_amount_requested') is-invalid @enderror">
            @error('funding_amount_requested') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 24px; margin-bottom: 4px;">Authors</h3>
            @error('authors') <p class="field-error">{{ $message }}</p> @enderror

            <div id="authors-container">
                <div class="claim-item-row" data-index="0">
                    <p class="queue-meta">Author 1</p>

                    <label>Name</label>
                    <input type="text" name="authors[0][name]" value="{{ old('authors.0.name') }}">
                    @error('authors.0.name') <p class="field-error">{{ $message }}</p> @enderror

                    <label style="display: block; margin-top: 8px;">
                        <input type="checkbox" name="authors[0][is_corresponding_author]" value="1"
                               @checked(old('authors.0.is_corresponding_author'))>
                        Corresponding / Presenting Author
                    </label>
                </div>
            </div>

            <button type="button" id="add-author-btn" class="btn-secondary" style="margin-top: 12px;">
                + Add Another Author
            </button>

            <label style="display: block; margin-top: 20px;">
                <input type="checkbox" name="requires_letter_of_undertaking" id="requires_letter_of_undertaking" value="1"
                       @checked(old('requires_letter_of_undertaking'))>
                A Letter of Undertaking is required for this application
            </label>

            <div id="lou-upload" style="@if (! old('requires_letter_of_undertaking')) display: none; @endif margin-top: 12px;">
                <label for="letter_of_undertaking">Letter of Undertaking</label>
                <input type="file" name="letter_of_undertaking" id="letter_of_undertaking"
                       class="@error('letter_of_undertaking') is-invalid @enderror">
                @error('letter_of_undertaking') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" style="margin-top: 20px;">Submit Application</button>
        </form>
    </div>
</div>

<template id="author-row-template">
    <div class="claim-item-row" data-index="__INDEX__">
        <p class="queue-meta">Author __LABEL__</p>
        <label>Name</label>
        <input type="text" name="authors[__INDEX__][name]">
        <label style="display: block; margin-top: 8px;">
            <input type="checkbox" name="authors[__INDEX__][is_corresponding_author]" value="1">
            Corresponding / Presenting Author
        </label>
        <button type="button" class="btn-secondary remove-author-btn" style="margin-top: 8px;">Remove</button>
    </div>
</template>

<script>
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

    document.getElementById('requires_letter_of_undertaking').addEventListener('change', function (e) {
        document.getElementById('lou-upload').style.display = e.target.checked ? '' : 'none';
    });
</script>
@endsection
