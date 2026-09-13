@extends('core::layouts.app')

@section('title', $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Submission')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>{{ $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Thesis Submission' }}</h2>
        <div class="card-divider"></div>

        @if ($returned)
            <p class="queue-meta">
                Replacing submission #{{ $returned->id }}. Upload the corrected thesis and
                say what you changed — CGS sees your response next to their own comments.
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

            <label for="thesis_document">Final Hardbound Thesis (PDF)</label>
            <input type="file" name="thesis_document" id="thesis_document" required
                   class="@error('thesis_document') is-invalid @enderror">
            @error('thesis_document') <p class="field-error">{{ $message }}</p> @enderror

            <label for="clearance_form">
                Clearance Form
                @if ($returned) <span style="color: var(--text-grey);">(optional if unchanged)</span> @endif
            </label>
            <input type="file" name="clearance_form" id="clearance_form" @unless ($returned) required @endunless
                   class="@error('clearance_form') is-invalid @enderror">
            @error('clearance_form') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">
                PDF, Word, Excel or an image, up to 10 MB each.
            </p>

            <button type="submit">{{ $returned ? 'Resubmit to CGS' : 'Submit to CGS' }}</button>
        </form>
    </div>
</div>
@endsection
