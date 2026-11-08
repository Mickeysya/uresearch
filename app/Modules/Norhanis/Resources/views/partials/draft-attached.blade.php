{{-- Under a file field on a draft: the file already saved for $label, with a box to remove it. --}}
@foreach ($attached->get($label, collect()) as $document)
    <p class="field-hint">
        Saved with your draft: <b>{{ $document->original_name }}</b>.
        Choose a new file to replace it, or
        <label style="display: inline; font-weight: 400; margin: 0;">
            <input type="checkbox" name="remove_documents[]" value="{{ $document->id }}" style="width: auto; margin: 0 4px 0 0;">remove it
        </label>.
    </p>
@endforeach
