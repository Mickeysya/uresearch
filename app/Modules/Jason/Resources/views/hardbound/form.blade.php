@extends('core::layouts.app')

@section('title', $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Submission')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>{{ $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Thesis Submission' }}</h2>
        <div class="card-divider"></div>

        @if ($awaiting->isNotEmpty())
            <div class="app-item" style="margin-bottom: 18px; border-left: 4px solid #A86A12;">
                <p><b>CGS returned {{ $awaiting->count() === 1 ? 'a submission' : $awaiting->count().' submissions' }} to you for correction</b></p>
                <ul class="doc-list">
                    @foreach ($awaiting as $app)
                        @php($last = $app->history->last())
                        <li>
                            <a href="{{ route('hardbound.resubmit.form', $app) }}"><b>Resubmit #{{ $app->id }}</b></a>
                            {{ $awaitingDetails[$app->id]->thesis_title ?? 'Hardbound submission' }}
                            @if ($last?->remarks) <br><span style="color: var(--text-grey);">CGS: “{{ $last->remarks }}”</span> @endif
                        </li>
                    @endforeach
                </ul>
                <p class="queue-meta" style="margin: 6px 0 0;">
                    Or, if you disagree with the return, file an appeal from the sidebar. The form
                    below starts a brand-new submission instead.
                </p>
            </div>
        @endif

        @if ($returned)
            <p class="queue-meta">
                Replacing submission #{{ $returned->id }}. Fix what was asked and say what
                you changed. The reviewer sees your response next to their own comments.
            </p>

            @php($lastRemark = $returned->history->last())
            @if ($lastRemark?->remarks)
                <div class="app-item" style="margin-bottom: 18px;">
                    <p><b>CGS comments</b>: {{ $lastRemark->stage_label }},
                        {{ $lastRemark->created_at?->format('j M Y') }}</p>
                    <p>{{ $lastRemark->remarks }}</p>
                </div>
            @endif
        @endif

        <form method="POST"
              action="{{ $returned ? route('hardbound.resubmit', $returned) : route('hardbound.store') }}"
              enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Get the form">
            <p class="fstep-hint">
                Start here. The Hardbound Thesis Submission form (UTP/CGS/021) comes
                pre-filled with your name, matric number and programme. Complete the
                rest, sign and date it, and upload it on the last step.
            </p>

            <p>
                <a href="{{ route('hardbound.template', 'submission') }}" class="btn-secondary">
                    Download the submission form (UTP/CGS/021)
                </a>
            </p>

            <p class="field-hint">
                There is a second CGS form, the Confirmation of Correction to Thesis
                (UTP/CGS/017A). You do not download that one: the portal generates it
                from what you enter next, and your supervisor and the viva chairman
                sign it electronically as they approve.
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Thesis &amp; candidate">
            <p class="fstep-hint">What goes on both CGS forms. Your matric number
               comes from your student record and cannot be edited here.</p>

            <label for="thesis_title">Title of Thesis <span style="color: var(--text-grey);">(not more than 15 words)</span></label>
            <textarea name="thesis_title" id="thesis_title" rows="3" required
                      class="@error('thesis_title') is-invalid @enderror">{{ old('thesis_title', $detail?->thesis_title) }}</textarea>
            @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

            <label for="matric_no_display">Matric Number</label>
            <input type="text" id="matric_no_display" value="{{ $student->matric_no }}" disabled>
            <p class="queue-meta" style="margin-top: -8px;">Taken from your student record.</p>

            <label for="programme">Programme</label>
            <input type="text" name="programme" id="programme" required
                   value="{{ old('programme', $detail?->programme ?? $student->programme) }}"
                   class="@error('programme') is-invalid @enderror">
            @error('programme') <p class="field-error">{{ $message }}</p> @enderror

            <label for="supervisor_name">Supervisor</label>
            <input type="text" name="supervisor_name" id="supervisor_name" required
                   value="{{ old('supervisor_name', $detail?->supervisor_name ?? $student->supervisor?->name) }}"
                   class="@error('supervisor_name') is-invalid @enderror">
            @error('supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            @if ($returned)
                <label for="response_to_comments">Your Response to the CGS Comments</label>
                <textarea name="response_to_comments" id="response_to_comments" rows="4" required
                          class="@error('response_to_comments') is-invalid @enderror">{{ old('response_to_comments') }}</textarea>
                @error('response_to_comments') <p class="field-error">{{ $message }}</p> @enderror
            @endif
            </fieldset>

            <fieldset class="fstep" data-label="Confirmation of Correction">
            <p class="fstep-hint">Fills UTP/CGS/017A. Your supervisor and the viva
               chairman sign this one electronically as they approve, so you do not
               upload it.</p>

            <label for="viva_date">Viva Date</label>
            <input type="date" name="viva_date" id="viva_date" required max="{{ now()->toDateString() }}"
                   value="{{ old('viva_date', $detail?->viva_date?->toDateString()) }}"
                   class="@error('viva_date') is-invalid @enderror">
            @error('viva_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="co_supervisor_name">Co-Supervisor <span style="color: var(--text-grey);">(optional)</span></label>
            <input type="text" name="co_supervisor_name" id="co_supervisor_name"
                   value="{{ old('co_supervisor_name', $detail?->co_supervisor_name) }}"
                   class="@error('co_supervisor_name') is-invalid @enderror">
            @error('co_supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <fieldset class="fstep" data-label="Signed UTP/CGS/021">
            <p class="fstep-hint">The form you downloaded above, completed, signed
               and dated.</p>

            <label for="submission_form">
                Signed Hardbound Thesis Submission form
                @if ($returned) <span style="color: var(--text-grey);">(re-upload only if it changed)</span> @endif
            </label>
            <input type="file" name="submission_form" id="submission_form" @unless ($returned) required @endunless
                   class="@error('submission_form') is-invalid @enderror">
            @error('submission_form') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">PDF, Word, Excel or an image, up to 10 MB.</p>
            </fieldset>

            <button type="submit">{{ $returned ? 'Resubmit for Confirmation' : 'Submit for Confirmation' }}</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
