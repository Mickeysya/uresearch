@extends('core::layouts.guest')

{{-- The reviewer's side of the Paper Evaluation Sheet (UTP/CGS/040). Reached
     from the signed link in their email; there is no login, and the page only
     ever shows the one paper that link names. --}}
@section('content')
@include('norhanis::publication._pinned_header')
@php($ratings = \App\Modules\Norhanis\Models\PublicationEvaluation::RATINGS)
<div class="card-container">
    <div class="card card-wide">
        <h2>Paper Evaluation Sheet</h2>
        <div class="card-divider"></div>

        <table class="recent-activity-table">
            <tr><th style="width: 30%;">Title</th><td>{{ $detail->title_of_paper }}</td></tr>
            <tr><th>Author</th><td>{{ $detail->authors->pluck('author_name')->implode(', ') }}</td></tr>
            <tr><th>{{ $detail->titleLabel() }}</th><td>{{ $detail->title_of_conference_journal }}</td></tr>
        </table>

        @if ($evaluation->isReplaced())
            <p class="message-error" style="margin-top: 16px;">
                This evaluation is no longer needed. The student has asked another reviewer. Thank you for your time.
            </p>
        @elseif ($evaluation->isSubmitted())
            <p class="message-success" style="margin-top: 16px;">
                Thank you, {{ $evaluation->reviewer_name }}. You submitted this evaluation on
                {{ $evaluation->signed_at->format('j M Y, g:ia') }} ({{ $evaluation->recommendationLabel() }}).
                There is nothing more to do, and you can close this page.
            </p>
        @elseif ($evaluation->isDeclined())
            <p class="message-success" style="margin-top: 16px;">
                Thank you for letting us know. The student has been told to ask another reviewer.
            </p>
        @else
            <p style="margin-top: 16px;">
                <a href="{{ $urls['paper'] }}" class="btn-secondary">Open the paper</a>
            </p>

            <p class="queue-meta">
                Rating:
                @foreach ($ratings as $score => $meaning) {{ $score }} = {{ $meaning }}@if (! $loop->last), @endif @endforeach
            </p>

            <form method="POST" action="{{ $urls['submit'] }}">
                @csrf

                @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::CRITERIA as $key => [$heading, $questions])
                    <fieldset style="margin-top: 16px;">
                        <legend><b>{{ $heading }}</b></legend>

                        @foreach ($questions as $question)
                            <p>{{ $question }}</p>
                        @endforeach

                        <div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px;">
                            @foreach ($ratings as $score => $meaning)
                                <div class="checkbox-row">
                                    <input type="radio" name="{{ $key }}_rating" id="{{ $key }}_rating_{{ $score }}" value="{{ $score }}"
                                           title="{{ $meaning }}" required @checked((string) old($key.'_rating') === (string) $score)>
                                    <label for="{{ $key }}_rating_{{ $score }}">{{ $score }}</label>
                                </div>
                            @endforeach
                        </div>
                        @error($key.'_rating') <p class="field-error">{{ $message }}</p> @enderror

                        <label for="{{ $key }}_comment">Comments</label>
                        <textarea name="{{ $key }}_comment" id="{{ $key }}_comment" rows="2"
                                  class="@error($key.'_comment') is-invalid @enderror">{{ old($key.'_comment') }}</textarea>
                        @error($key.'_comment') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>
                @endforeach

                <fieldset style="margin-top: 16px;">
                    <legend><b>Additional Comments</b></legend>
                    @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::ADDITIONAL_QUESTIONS as $question)
                        <p>{{ $question }}</p>
                    @endforeach
                    <label for="additional_comments">Comments <span style="color: var(--text-grey)">(required unless you choose "Recommended")</span></label>
                    <textarea name="additional_comments" id="additional_comments" rows="4"
                              class="@error('additional_comments') is-invalid @enderror">{{ old('additional_comments') }}</textarea>
                    @error('additional_comments') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset style="margin-top: 16px;">
                    <legend><b>Recommendation</b></legend>
                    @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::recommendations() as $value => $label)
                        <div class="checkbox-row">
                            <input type="radio" name="recommendation" id="recommendation_{{ $value }}" value="{{ $value }}"
                                   required @checked(old('recommendation') === $value)>
                            <label for="recommendation_{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                    @error('recommendation') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <p style="margin-top: 16px;"><b>Evaluated by:</b> {{ $evaluation->reviewer_name }}</p>

                <label>Your signature</label>
                @include('norhanis::publication._signature_pad', ['name' => 'signature'])

                <div class="checkbox-row" style="margin-top: 12px;">
                    <input type="checkbox" name="confirms" id="confirms" value="1" required>
                    <label for="confirms">This is my own evaluation of the paper.</label>
                </div>
                @error('confirms') <p class="field-error">{{ $message }}</p> @enderror

                <button type="submit" style="margin-top: 16px;">Submit evaluation</button>
            </form>

            <p class="queue-meta" style="margin-top: 16px;">
                Your ratings, comments and signature are recorded with the date and time, saved as a PDF,
                and sent to the student.
            </p>

            <div class="card-divider"></div>

            <form method="POST" action="{{ $urls['decline'] }}">
                @csrf
                <p><b>Unable to review this paper?</b></p>
                <label for="decline_reason">Reason</label>
                <input type="text" name="decline_reason" id="decline_reason" maxlength="500" required
                       value="{{ old('decline_reason') }}" class="@error('decline_reason') is-invalid @enderror">
                @error('decline_reason') <p class="field-error">{{ $message }}</p> @enderror
                <button type="submit" class="btn-secondary" style="margin-top: 8px;">I can't review this paper</button>
            </form>
        @endif
    </div>
</div>
@endsection
