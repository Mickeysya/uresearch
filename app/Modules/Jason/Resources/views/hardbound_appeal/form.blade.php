@extends('core::layouts.app')

@section('title', 'Appeal for an Extension')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Appeal Hardbound Submission"
        subtitle="Appeal for an extension of your hardbound thesis submission deadline." />

    <div class="card card-wide">
        @if ($open)
            <div class="empty-state">
                <p>Appeal <b>#{{ $open->id }}</b> is still being processed.</p>
                <p class="queue-meta">
                    One appeal at a time: a second memo would reach CGS as two appeals for the
                    same deadline. Track this one from your applications page.
                </p>
                <a href="{{ route('applications.index') }}" class="btn">Track appeal #{{ $open->id }}</a>
            </div>
        @else
            <p class="queue-meta">
                This writes the CGS memo, <b>Appeal for Extension of Hardbound Thesis
                Submission</b>, for you. It is addressed to the Dean of Postgraduate and
                Research and goes through your supervisor and your HOD/Chair, who each endorse
                it and whose signatures are stamped onto it, before reaching CGS.
                You do not need to print or upload anything.
            </p>

            <form method="POST" action="{{ route('hardbound-appeal.store') }}"
                  class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Dates">
                <p class="fstep-hint">The deadline you are working to, and the one you
                   are asking for.</p>

                <label for="original_deadline">Current Submission Deadline
                    <span style="color: var(--text-grey);">(optional)</span></label>
                <input type="date" name="original_deadline" id="original_deadline"
                       value="{{ old('original_deadline') }}"
                       class="@error('original_deadline') is-invalid @enderror">
                @error('original_deadline') <p class="field-error">{{ $message }}</p> @enderror

                <label for="requested_until">Extension Requested Until</label>
                <input type="date" name="requested_until" id="requested_until" required
                       value="{{ old('requested_until') }}"
                       class="@error('requested_until') is-invalid @enderror">
                @error('requested_until') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Your memo">
                <p class="fstep-hint">Written in your own words, and printed as the body
                   of the memo the Dean reads.</p>

                <label for="reason">Reason for the Appeal</label>
                <textarea name="reason" id="reason" rows="10" required
                          placeholder="Explain why you cannot submit your hardbound thesis by the deadline, and what remains to be done."
                          class="@error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    Leave a blank line between paragraphs. Your name, student ID
                    ({{ $student->matric_no ?: 'not on your record' }}) and department
                    ({{ $student->department ?: 'not on your record' }}) are filled in from your
                    own record, and the date the memo carries is the date you file it.
                </p>
                </fieldset>

                <button type="submit">File Appeal</button>
            </form>

            @include('core::partials.form-stepper')
        @endif
    </div>
</div>
@endsection
