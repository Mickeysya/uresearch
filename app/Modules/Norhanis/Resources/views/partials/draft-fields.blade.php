{{-- Inside the form, after @csrf: which draft this is, and the step to reopen on. --}}
@if ($draft)
    <input type="hidden" name="draft_id" value="{{ $draft->id }}">
    <p class="message-info">
        Continuing your draft, last saved {{ ($draft->updated_at ?? $draft->created_at)->format('j M Y, g:ia') }}.
        Files you attached before are kept unless you remove them.
    </p>
@endif
@error('draft') <p class="field-error">{{ $message }}</p> @enderror
<input type="hidden" name="draft_step" value="{{ $draftStep }}" data-draft-step>
