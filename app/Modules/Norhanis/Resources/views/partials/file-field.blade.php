{{--
    One document, one file: Travel, Publication and Student Claims.

    @include('norhanis::partials.file-field', [
        'name' => 'receipt', 'label' => 'Receipt',
        'required' => true,                       // false for an optional document
        'allowed' => ['pdf', 'jpg', 'jpeg', 'png'], // omit for PDF/JPG/PNG/DOCX
        'hint' => 'Required for international students only.', // optional
    ])

    A file saved with a draft is listed under the input with a box to remove
    it; it satisfies "required", and picking a new file replaces it
    (Concerns\SavesDrafts::attachDraftFiles()). The server checks the same
    limits: Concerns\SavesDrafts::documentRules().
--}}
@php
    $allowed = $allowed ?? \App\Modules\Core\Services\DocumentStore::MULTI_ALLOWED;
    $optional = ! ($required ?? true);
    $required = ! $optional && ! $attached->has($label);
    $maxMb = \App\Modules\Core\Services\DocumentStore::MULTI_MAX_FILE_KB / 1024;
    $types = count($allowed) === 1
        ? strtoupper($allowed[0])
        : strtoupper(implode(', ', array_slice($allowed, 0, -1))).' or '.strtoupper(last($allowed));
@endphp
<label for="{{ $name }}">{{ $label }}
    @if ($optional)
        <span style="color: var(--text-grey)">(optional)</span>
    @endif
</label>
<input type="file" name="{{ $name }}" id="{{ $name }}"
       accept="{{ implode(',', array_map(fn ($ext) => '.'.$ext, $allowed)) }}"
       @required($required)
       class="@error($name) is-invalid @enderror">
@error($name) <p class="field-error">{{ $message }}</p> @enderror
@include('norhanis::partials.draft-attached', ['label' => $label])
@if (! empty($hint))
    <p class="field-hint">{{ $hint }}</p>
@endif
<p class="field-hint">One file, {{ $types }}, {{ $maxMb }}&nbsp;MB max.</p>
