{{--
    One Section C row. Expects $i (an index, or '__INDEX__' in the
    <template> the "Add a publication" button clones), $row (array or
    null) and $options (config('nureen.ga_extension.publications')).
    A row left wholly empty is dropped on save --
    GaExtensionController::normalisePublications().
--}}
@php
    $row = $row ?? [];
    $name = fn (string $field) => "publications[{$i}][{$field}]";
    $id = fn (string $field) => "pub-{$i}-{$field}";
    $error = fn (string $field) => $i === '__INDEX__' ? null : $errors->first("publications.{$i}.{$field}");
@endphp
<div class="ga-pub" data-publication-row>
    <div class="ga-pub-head">
        <b>Publication <span data-publication-number>{{ is_int($i) ? $i + 1 : '' }}</span></b>
        <button type="button" class="btn-secondary" data-publication-remove>Remove</button>
    </div>

    <div class="ga-pub-grid">
        <div>
            <label for="{{ $id('type') }}">Type of publication</label>
            <select name="{{ $name('type') }}" id="{{ $id('type') }}" class="{{ $error('type') ? 'is-invalid' : '' }}">
                <option value="">Select&hellip;</option>
                @foreach ($options['types'] as $value => $label)
                    <option value="{{ $value }}" @selected(($row['type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($error('type')) <p class="field-error">{{ $error('type') }}</p> @endif
        </div>
        <div>
            <label for="{{ $id('status') }}">Status</label>
            <select name="{{ $name('status') }}" id="{{ $id('status') }}" class="{{ $error('status') ? 'is-invalid' : '' }}">
                <option value="">Select&hellip;</option>
                @foreach ($options['statuses'] as $value => $label)
                    <option value="{{ $value }}" @selected(($row['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($error('status')) <p class="field-error">{{ $error('status') }}</p> @endif
        </div>
        <div>
            <label for="{{ $id('year') }}">Publication year</label>
            <input type="number" name="{{ $name('year') }}" id="{{ $id('year') }}" min="1950" max="{{ now()->year + 1 }}"
                   value="{{ $row['year'] ?? '' }}" class="{{ $error('year') ? 'is-invalid' : '' }}">
            @if ($error('year')) <p class="field-error">{{ $error('year') }}</p> @endif
        </div>
    </div>

    @foreach (['authors' => ['Authors list', 500, 'e.g. A. Danial, A. Rahman'], 'title' => ['Publication title', 300, null], 'venue' => ['Journal / conference / book name', 200, null]] as $field => [$label, $max, $placeholder])
        <label for="{{ $id($field) }}">{{ $label }}</label>
        <input type="text" name="{{ $name($field) }}" id="{{ $id($field) }}" maxlength="{{ $max }}"
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif
               value="{{ $row[$field] ?? '' }}" class="{{ $error($field) ? 'is-invalid' : '' }}">
        @if ($error($field)) <p class="field-error">{{ $error($field) }}</p> @endif
    @endforeach

    <div class="ga-pub-grid">
        @foreach (['volume' => ['Volume (optional)', 30], 'pages' => ['Pages (optional)', 30], 'doi' => ['DOI (optional)', 100], 'issn' => ['ISSN no. (optional)', 20]] as $field => [$label, $max])
            <div>
                <label for="{{ $id($field) }}">{{ $label }}</label>
                <input type="text" name="{{ $name($field) }}" id="{{ $id($field) }}" maxlength="{{ $max }}"
                       @if ($field === 'issn') placeholder="1234-5678" @endif
                       value="{{ $row[$field] ?? '' }}" class="{{ $error($field) ? 'is-invalid' : '' }}">
                @if ($error($field)) <p class="field-error">{{ $error($field) }}</p> @endif
            </div>
        @endforeach
    </div>

    <div class="ga-pub-grid ga-pub-grid-3">
        <div>
            <label for="{{ $id('database') }}">Database (optional)</label>
            <select name="{{ $name('database') }}" id="{{ $id('database') }}">
                <option value="">Select&hellip;</option>
                @foreach ($options['databases'] as $value => $label)
                    <option value="{{ $value }}" @selected(($row['database'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="{{ $id('quartile') }}">Quartile (optional)</label>
            <select name="{{ $name('quartile') }}" id="{{ $id('quartile') }}">
                <option value="">Select&hellip;</option>
                @foreach ($options['quartiles'] as $value => $label)
                    <option value="{{ $value }}" @selected(($row['quartile'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="{{ $id('impact_factor') }}">Impact factor (optional)</label>
            <input type="number" name="{{ $name('impact_factor') }}" id="{{ $id('impact_factor') }}" min="0" max="1000" step="0.001"
                   value="{{ $row['impact_factor'] ?? '' }}" class="{{ $error('impact_factor') ? 'is-invalid' : '' }}">
            @if ($error('impact_factor')) <p class="field-error">{{ $error('impact_factor') }}</p> @endif
        </div>
    </div>
</div>
