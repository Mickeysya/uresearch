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
                Download the CGS memo, <b>Appeal for Extension of Hardbound Thesis
                Submission</b>, write your appeal on it in your own words, sign it, and upload
                it back here. It is already addressed to the Dean of Postgraduate and Research
                and carries your name, student ID and department. Your supervisor and your
                HOD/Chair then endorse it before it reaches CGS.
            </p>

            <form method="POST" action="{{ route('hardbound-appeal.store') }}"
                  enctype="multipart/form-data" class="app-form" data-stepper>
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

                <fieldset class="fstep" data-label="Get the memo">
                <p class="fstep-hint">Pre-filled with your name, student ID
                   ({{ $student->matric_no ?: 'not on your record' }}) and department
                   ({{ $student->department ?: 'not on your record' }}). The reason is
                   yours to write.</p>

                <a href="{{ route('hardbound-appeal.template') }}" class="btn-secondary">
                    Download the memo
                </a>
                <p class="queue-meta">
                    Write why you cannot submit your hardbound thesis by the deadline and what
                    remains to be done, then sign it above your name.
                </p>
                </fieldset>

                <fieldset class="fstep" data-label="Upload it back">
                <p class="fstep-hint">Your completed, signed memo. This is the document
                   your supervisor, your HOD/Chair and the Dean read.</p>

                <label for="memo">Completed Memo</label>
                <input type="file" name="memo" id="memo" required
                       class="@error('memo') is-invalid @enderror">
                @error('memo') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    PDF, Word, Excel or an image, up to 10 MB. It is stored exactly as you
                    upload it: the portal never edits what you wrote.
                </p>
                </fieldset>

                <button type="submit">File Appeal</button>
            </form>

            @include('core::partials.form-stepper')
        @endif
    </div>
</div>
@endsection
