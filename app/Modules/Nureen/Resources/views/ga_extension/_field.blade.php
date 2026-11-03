{{--
    One Section B field from config('nureen.ga_extension.applicant_fields').
    Expects $key, $field and $detail (the draft's detail row, or null).

    A sensitive field (account no., IC/passport no.) is never shown back:
    the input stays empty, and "On file" shows the masked value. Leaving it
    blank keeps what is stored -- GaExtensionController::applicantValues().
--}}
@php
    $type = $field['type'] ?? 'text';
    $sensitive = ! empty($field['sensitive']);
    $onFile = $sensitive && filled($detail?->{$key});
    $stored = $detail?->{$key};
    $value = $sensitive ? null : old($key, $stored instanceof \Carbon\CarbonInterface ? $stored->format('Y-m-d') : $stored);
    $required = ($field['required'] ?? false) && ! $onFile;
    $id = 'field-'.$key;
@endphp

<div @if (isset($field['required_if'])) data-visa-only hidden @endif>
@if ($type === 'radio' || $type === 'yesno')
    @php
        $choices = $type === 'yesno' ? ['1' => 'Yes', '0' => 'No'] : $field['options'];
        $current = $type === 'yesno' ? ($value === null ? null : ((string) (int) filter_var($value, FILTER_VALIDATE_BOOLEAN))) : $value;
    @endphp
    <fieldset class="ga-choice @error($key) is-invalid @enderror" @if ($type === 'yesno') data-visa-toggle @endif>
        <legend>{{ $field['label'] }}</legend>
        @foreach ($choices as $optionValue => $optionLabel)
            <label class="ga-choice-option">
                <input type="radio" name="{{ $key }}" value="{{ $optionValue }}" @required($required)
                       @checked($current !== null && (string) $current === (string) $optionValue)>
                {{ $optionLabel }}
            </label>
        @endforeach
    </fieldset>
@else
    <label for="{{ $id }}">
        {{ $field['label'] }}
        @unless ($field['required'] ?? isset($field['required_if']))
            <span style="color: var(--text-grey)">(optional)</span>
        @endunless
    </label>
    @if ($type === 'select')
        <select name="{{ $key }}" id="{{ $id }}" @required($required) class="@error($key) is-invalid @enderror">
            <option value="">Select&hellip;</option>
            @foreach ($field['options'] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <input type="{{ $type === 'tel' ? 'tel' : ($type === 'email' ? 'email' : ($type === 'date' ? 'date' : 'text')) }}"
               name="{{ $key }}" id="{{ $id }}" @required($required)
               @if (isset($field['max'])) maxlength="{{ $field['max'] }}" @endif
               @if ($key === 'admission_date') max="{{ now()->format('Y-m-d') }}" @endif
               @if (isset($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
               @if ($sensitive) autocomplete="off" @elseif ($type === 'email') autocomplete="email" @elseif ($type === 'tel') autocomplete="tel" @endif
               value="{{ $value }}"
               @if ($onFile) aria-describedby="{{ $id }}-on-file" @endif
               class="@error($key) is-invalid @enderror">
    @endif
    @if ($onFile)
        <p class="queue-meta" id="{{ $id }}-on-file">On file: {{ \App\Modules\Nureen\Models\GaExtensionDetail::mask($detail->{$key}) }}. Leave blank to keep it.</p>
    @endif
@endif
@error($key) <p class="field-error">{{ $message }}</p> @enderror
</div>
