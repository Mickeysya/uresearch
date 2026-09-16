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
                            — {{ $awaitingDetails[$app->id]->thesis_title ?? 'Hardbound submission' }}
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

        <div class="app-item" style="margin-bottom: 18px;">
            <p><b>What this submission produces</b></p>
            <ul class="doc-list">
                <li>
                    <a href="{{ route('hardbound.template', 'submission') }}">Download — Hardbound Thesis Submission form (PDF)</a>
                    <span style="color: var(--text-grey);">— complete, sign it yourself, and upload it below.</span>
                </li>
                <li>
                    <b>Confirmation of Correction to Thesis</b>
                    <span style="color: var(--text-grey);">— generated from your declaration below. Your supervisor,
                    the Chair and CGS sign it electronically as they approve; you do not upload this one.</span>
                </li>
            </ul>
        </div>

        @if ($returned)
            <p class="queue-meta">
                Replacing submission #{{ $returned->id }}. Fix what was asked and say what
                you changed — the reviewer sees your response next to their own comments.
            </p>

            @php($lastRemark = $returned->history->last())
            @if ($lastRemark?->remarks)
                <div class="app-item" style="margin-bottom: 18px;">
                    <p><b>CGS comments</b> — {{ $lastRemark->stage_label }},
                        {{ $lastRemark->created_at?->format('j M Y') }}</p>
                    <p>{{ $lastRemark->remarks }}</p>
                </div>
            @endif
        @endif

        <form method="POST"
              action="{{ $returned ? route('hardbound.resubmit', $returned) : route('hardbound.store') }}"
              enctype="multipart/form-data">
            @csrf

            <label for="thesis_title">Thesis Title</label>
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

            <h3 style="margin-top: 22px;">Confirmation of Correction to Thesis</h3>

            <label for="corrections_made">Corrections made to the thesis</label>
            <textarea name="corrections_made" id="corrections_made" rows="7" required
                      placeholder="List each correction required by the examiners and what you changed, e.g.&#10;1. Chapter 3 — methodology rewritten to state the sampling frame (Examiner 1, item 2.7)&#10;2. Figures 4.2–4.6 relabelled with units (Examiner 2, section 3)"
                      class="@error('corrections_made') is-invalid @enderror">{{ old('corrections_made', $detail?->corrections_made) }}</textarea>
            <p class="queue-meta" style="margin-top: -8px;">
                Printed on the Confirmation exactly as written — this is what your supervisor signs off.
            </p>
            @error('corrections_made') <p class="field-error">{{ $message }}</p> @enderror

            <label style="display: flex; gap: 10px; align-items: flex-start; font-weight: normal;">
                <input type="checkbox" name="declaration" id="declaration" value="1" required
                       style="margin-top: 4px;" @checked(old('declaration'))>
                <span>
                    I confirm that the corrections required by the examiners have been made to the
                    thesis as listed above, and that the hardbound copy submitted incorporates all of them.
                </span>
            </label>
            @error('declaration') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 22px;">Hardbound Thesis Submission form</h3>

            <label for="submission_form">
                Signed Hardbound Thesis Submission form
                @if ($returned) <span style="color: var(--text-grey);">(re-upload only if it changed)</span> @endif
            </label>
            <input type="file" name="submission_form" id="submission_form" @unless ($returned) required @endunless
                   class="@error('submission_form') is-invalid @enderror">
            @error('submission_form') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">PDF, Word, Excel or an image, up to 10 MB.</p>

            <button type="submit">{{ $returned ? 'Resubmit for Confirmation' : 'Submit for Confirmation' }}</button>
        </form>
    </div>
</div>
@endsection
