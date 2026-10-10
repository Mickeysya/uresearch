{{--
    Multi-file "Supporting Documents" picker — GA Extension and Supervisor
    Request share this, since both ask for the same thing: 1 to 5 files,
    PDF/JPG/PNG/DOCX, 5 MB each, 20 MB combined. See
    Core\Services\DocumentStore::manyRules()/attachMany() for the backend
    half of these same limits — this file only ever produces a nicer error
    sooner; the server re-checks everything regardless.

    USAGE:
        <x-nureen::multi-file-upload
            name="supporting_documents"
            label="Supporting Documents"
            :required="true"
            hint="Your research proposal, or whatever your department asks for." />

    One real file input (name="{name}[]", multiple) is what the form submits.
    Re-selecting files does not replace it: the script keeps its own list of
    staged files and rebuilds the input's FileList (via DataTransfer) on every
    add or remove, which is also what lets a file be un-staged with the ✕
    button. Without JavaScript this degrades to a plain multi-select file
    input — still usable for the common case, just without the running list,
    the remove buttons or the client-side messages. The server validates
    everything either way.
--}}
@props([
    'name',
    'label',
    'required' => true,
    'hint' => null,
])
@php
    $maxFiles = \App\Modules\Core\Services\DocumentStore::MULTI_MAX_FILES;
    $maxFileMb = \App\Modules\Core\Services\DocumentStore::MULTI_MAX_FILE_KB / 1024;
    $maxTotalMb = \App\Modules\Core\Services\DocumentStore::MULTI_MAX_TOTAL_KB / 1024;
    $allowed = \App\Modules\Core\Services\DocumentStore::MULTI_ALLOWED;
    $accept = implode(',', array_map(fn ($ext) => ".{$ext}", $allowed));
    $inputId = $name.'-input-'.uniqid();
@endphp

<div class="doc-upload" data-doc-upload
     data-max-files="{{ $maxFiles }}"
     data-max-file-bytes="{{ $maxFileMb * 1024 * 1024 }}"
     data-max-total-bytes="{{ $maxTotalMb * 1024 * 1024 }}"
     data-allowed="{{ implode(',', $allowed) }}">

    <label for="{{ $inputId }}">
        {{ $label }}
        <span style="color: var(--text-grey)">({{ $required ? 'required' : 'optional' }}, up to {{ $maxFiles }} files)</span>
    </label>

    <input type="file" id="{{ $inputId }}" name="{{ $name }}[]" multiple
           accept="{{ $accept }}"
           data-doc-upload-input
           @required($required)
           class="@error($name) is-invalid @enderror">

    @error($name) <p class="field-error">{{ $message }}</p> @enderror
    @foreach ($errors->get($name.'.*') as $fieldErrors)
        @foreach ($fieldErrors as $message)
            <p class="field-error">{{ $message }}</p>
        @endforeach
    @endforeach

    <ul class="doc-upload-list" data-doc-upload-list hidden></ul>
    {{-- field-error is added/removed by the script, not written here: a
         static class on an empty placeholder is indistinguishable from a
         real error to form-stepper's "open on the step the server
         complained about" scan, which matches on the class alone and would
         always land here, even on a blank form. --}}
    <p data-doc-upload-error hidden></p>

    @if ($hint)
        <p class="queue-meta" style="margin-top: -8px;">{{ $hint }}</p>
    @endif
    <p class="queue-meta" style="margin-top: -8px;">
        PDF, JPG, PNG or DOCX. Up to {{ $maxFiles }} files, {{ rtrim(rtrim(number_format($maxFileMb, 1), '0'), '.') }}&nbsp;MB
        each, {{ rtrim(rtrim(number_format($maxTotalMb, 1), '0'), '.') }}&nbsp;MB total.
    </p>
</div>

<style>
    .doc-upload-list {
        list-style: none;
        margin: var(--space-2) 0 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
    }

    .doc-upload-item {
        display: flex;
        align-items: center;
        gap: var(--space-3);
        padding: var(--space-2) var(--space-3);
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-sm);
        background: var(--surface);
    }

    .doc-upload-item-name {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: var(--text-sm);
        color: var(--text-dark);
    }

    .doc-upload-item-size {
        flex-shrink: 0;
        font-size: var(--text-sm);
        color: var(--text-grey);
    }

    .doc-upload-item-remove {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        padding: 0;
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-full);
        background: var(--surface);
        color: var(--text-grey);
        font-size: var(--text-sm);
        line-height: 1;
        cursor: pointer;
    }

    .doc-upload-item-remove:hover {
        background: var(--danger-bg);
        color: var(--danger-fg);
        border-color: var(--danger-border);
    }
</style>

@once
    @push('scripts')
        <script @cspNonce>
        (function () {
            function humanSize(bytes) {
                if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
                return Math.max(1, Math.round(bytes / 1024)) + ' KB';
            }

            function extOf(filename) {
                var parts = filename.split('.');
                return parts.length > 1 ? parts.pop().toLowerCase() : '';
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-doc-upload]'), function (widget) {
                var input = widget.querySelector('[data-doc-upload-input]');
                var list = widget.querySelector('[data-doc-upload-list]');
                var errorBox = widget.querySelector('[data-doc-upload-error]');

                var maxFiles = parseInt(widget.dataset.maxFiles, 10);
                var maxFileBytes = parseInt(widget.dataset.maxFileBytes, 10);
                var maxTotalBytes = parseInt(widget.dataset.maxTotalBytes, 10);
                var allowed = widget.dataset.allowed.split(',');

                // The source of truth. input.files is rebuilt from this on
                // every change, which is what lets a second selection add to
                // the first instead of replacing it -- a plain file input
                // cannot do that on its own.
                var staged = [];

                function totalBytes() {
                    return staged.reduce(function (sum, f) { return sum + f.size; }, 0);
                }

                function showErrors(messages) {
                    if (! messages.length) {
                        errorBox.hidden = true;
                        errorBox.textContent = '';
                        errorBox.classList.remove('field-error');
                        return;
                    }
                    errorBox.hidden = false;
                    errorBox.classList.add('field-error');
                    errorBox.innerHTML = '';
                    messages.forEach(function (msg) {
                        var p = document.createElement('div');
                        p.textContent = msg;
                        errorBox.appendChild(p);
                    });
                }

                function syncInput() {
                    var dt = new DataTransfer();
                    staged.forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;
                    // required is satisfied by input.files.length > 0; clear
                    // any custom message now that the list changed.
                    input.setCustomValidity('');
                }

                function render() {
                    list.innerHTML = '';
                    list.hidden = staged.length === 0;

                    staged.forEach(function (file, index) {
                        var li = document.createElement('li');
                        li.className = 'doc-upload-item';

                        var name = document.createElement('span');
                        name.className = 'doc-upload-item-name';
                        name.textContent = file.name;
                        name.title = file.name;

                        var size = document.createElement('span');
                        size.className = 'doc-upload-item-size';
                        size.textContent = humanSize(file.size);

                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'doc-upload-item-remove';
                        remove.setAttribute('aria-label', 'Remove ' + file.name);
                        remove.textContent = '×';
                        remove.addEventListener('click', function () {
                            staged.splice(index, 1);
                            syncInput();
                            render();
                            showErrors([]);
                        });

                        li.appendChild(name);
                        li.appendChild(size);
                        li.appendChild(remove);
                        list.appendChild(li);
                    });
                }

                input.addEventListener('change', function () {
                    var picked = Array.prototype.slice.call(input.files);
                    var errors = [];

                    for (var i = 0; i < picked.length; i++) {
                        var file = picked[i];

                        if (staged.length >= maxFiles) {
                            errors.push('You can attach at most ' + maxFiles + ' files.');
                            break;
                        }

                        if (allowed.indexOf(extOf(file.name)) === -1) {
                            errors.push('"' + file.name + '" is not an allowed file type. Use PDF, JPG, PNG or DOCX.');
                            continue;
                        }

                        if (file.size > maxFileBytes) {
                            errors.push('"' + file.name + '" is larger than ' + humanSize(maxFileBytes) + '.');
                            continue;
                        }

                        if (totalBytes() + file.size > maxTotalBytes) {
                            errors.push('Adding "' + file.name + '" would put the total over ' + humanSize(maxTotalBytes) + '.');
                            continue;
                        }

                        staged.push(file);
                    }

                    showErrors(errors);
                    syncInput();
                    render();
                });

                input.addEventListener('invalid', function () {
                    if (staged.length === 0) {
                        input.setCustomValidity('Select at least one supporting document.');
                    }
                });
            });
        })();
        </script>
    @endpush
@endonce
