@extends('core::layouts.app')

@section('title', $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Submission')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="{{ $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Thesis Submission' }}" />

    <div class="card card-wide">
        @if ($awaiting->isNotEmpty())
            <div class="app-item" style="margin-bottom: 18px; border-left: 4px solid #A86A12;">
                <p><b>{{ $awaiting->count() === 1 ? 'A submission was' : $awaiting->count().' submissions were' }} returned to you for correction</b></p>
                <ul class="doc-list">
                    @foreach ($awaiting as $app)
                        @php($last = $app->history->last())
                        <li>
                            <b>#{{ $app->id }}</b>: {{ $awaitingDetails[$app->id]->thesis_title ?? 'Hardbound submission' }}
                            @if ($last)
                                <br><span style="color: var(--text-grey);">
                                    {{ $last->stage_label }}@if ($last->remarks): “{{ $last->remarks }}”@endif
                                </span>
                            @endif
                            <br>
                            <a href="{{ route('hardbound.resubmit.form', $app) }}"><b>Resubmit it</b></a>
                        </li>
                    @endforeach
                </ul>
                <p class="queue-meta" style="margin: 6px 0 0;">
                    Correct what was asked and resubmit. The form below starts a brand-new
                    submission instead.
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
              class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="About you">
            <p class="fstep-hint">
                These fill the Hardbound Thesis Submission form (UTP/CGS/021), which the
                portal writes for you. Nothing to download, print or scan: your name,
                matric number and programme come from your student record, and these are
                the boxes the form asks you for.
            </p>

            <label for="country">Country</label>
            <input type="text" name="country" id="country" maxlength="6"
                   placeholder="e.g. MYS"
                   value="{{ old('country', $detail?->country) }}"
                   class="@error('country') is-invalid @enderror">
            @error('country') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">
                Six boxes on UTP/CGS/021, so a short code rather than the full name.
            </p>

            <label for="intake">Intake</label>
            <input type="text" name="intake" id="intake" maxlength="6"
                   placeholder="e.g. SEP23"
                   value="{{ old('intake', $detail?->intake) }}"
                   class="@error('intake') is-invalid @enderror">
            @error('intake') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">Six boxes on the form, like the country.</p>

            <label for="passport_no">Passport / IC Number</label>
            <input type="text" name="passport_no" id="passport_no" maxlength="40"
                   value="{{ old('passport_no', $detail?->passport_no) }}"
                   class="@error('passport_no') is-invalid @enderror">
            @error('passport_no') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">Printed on your own copy of UTP/CGS/021 and nowhere else.</p>

            <label for="date_of_birth">Date of Birth</label>
            <input type="date" name="date_of_birth" id="date_of_birth" max="{{ now()->toDateString() }}"
                   value="{{ old('date_of_birth', $detail?->date_of_birth?->toDateString()) }}"
                   class="@error('date_of_birth') is-invalid @enderror">
            @error('date_of_birth') <p class="field-error">{{ $message }}</p> @enderror

            <p class="field-hint">
                The second CGS form, the Confirmation of Correction to Thesis
                (UTP/CGS/017A), is written for you too. Your supervisor and the viva
                chairman sign that one electronically as they approve.
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
            <p class="field-hint">Taken from your student record.</p>

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

            <button type="submit">{{ $returned ? 'Resubmit for Confirmation' : 'Submit for Confirmation' }}</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
