@extends('core::layouts.guest')

{{-- The co-author's side of the Authorship Certification. Reached from the
     signed link in their email; there is no login, and the page only ever
     shows the one paper that link names. --}}
@section('content')
@include('norhanis::publication._pinned_header')
<div class="card-container">
    <div class="card">
        <h2>Authorship Certification</h2>
        <div class="card-divider"></div>

        <p><b>Title of Paper:</b> {{ $detail->title_of_paper }}</p>
        <p><b>{{ $detail->titleLabel() }}:</b> {{ $detail->title_of_conference_journal }}</p>

        <p style="margin-top: 12px;"><b>Authors, in the order listed on the manuscript:</b></p>
        <ol style="margin: 4px 0 0 20px;">
            @foreach ($detail->authors as $listed)
                <li>
                    @if ($listed->is($author)) <b>{{ $listed->author_name }} (you)</b> @else {{ $listed->author_name }} @endif
                </li>
            @endforeach
        </ol>

        @if ($author->hasSigned())
            <p class="message-success" style="margin-top: 16px;">
                Thank you, {{ $author->author_name }}. You signed this certification on
                {{ $author->signed_at->format('j M Y, g:ia') }}. There is nothing more to do, and you can close this page.
            </p>
        @else
            <p style="margin-top: 16px;"><b>Authorship</b></p>
            <p>An author is generally considered to be an individual or a group who has made substantial intellectual contributions in the:</p>
            <ol style="margin: 4px 0 0 20px;">
                <li>Conception and design, or analysis and interpretation of data, AND</li>
                <li>Drafting the articles or revising it critically for intellectual content, AND</li>
                <li>Final approval of the version to be published.</li>
            </ol>
            <p style="margin-top: 8px;">No part of the work described has been published before or is under consideration for publication elsewhere.</p>

            <form method="POST" action="{{ $action }}" style="margin-top: 16px;">
                @csrf

                <p><b>Conflict of Interest</b></p>
                <p>All authors must disclose any affiliation with, or financial support from, any organisation with a direct financial interest in the subject matter of the manuscript.</p>

                <div class="checkbox-row">
                    <input type="radio" name="has_conflict" id="has_conflict_no" value="no" @checked(old('has_conflict', 'no') === 'no')>
                    <label for="has_conflict_no">I have none to disclose</label>
                </div>
                <div class="checkbox-row">
                    <input type="radio" name="has_conflict" id="has_conflict_yes" value="yes" @checked(old('has_conflict') === 'yes')>
                    <label for="has_conflict_yes">I have something to disclose</label>
                </div>
                @error('has_conflict') <p class="field-error">{{ $message }}</p> @enderror

                <label for="conflict_disclosure">What you are disclosing <span style="color: var(--text-grey)">(only if you chose the second option)</span></label>
                <textarea name="conflict_disclosure" id="conflict_disclosure" rows="3"
                          class="@error('conflict_disclosure') is-invalid @enderror">{{ old('conflict_disclosure') }}</textarea>
                @error('conflict_disclosure') <p class="field-error">{{ $message }}</p> @enderror

                <div class="checkbox-row" style="margin-top: 16px;">
                    <input type="checkbox" name="certifies" id="certifies" value="1" required>
                    <label for="certifies">I certify that the work submitted is in full accordance with the Authorship Policy of Universiti Teknologi PETRONAS, and I agree on the author order listed above.</label>
                </div>
                @error('certifies') <p class="field-error">{{ $message }}</p> @enderror

                <label>Your signature</label>
                @include('norhanis::publication._signature_pad', ['name' => 'signature'])

                <button type="submit" style="margin-top: 16px;">Certify authorship</button>
            </form>

            <p class="queue-meta" style="margin-top: 16px;">
                Your signature, the date and the time are recorded with this application and printed on the completed form.
                If you should not be listed, or the order is wrong, do not sign: tell the author who listed you.
            </p>
        @endif
    </div>
</div>
@endsection
