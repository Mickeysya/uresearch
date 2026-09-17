@extends('core::layouts.app')

@section('title', 'My Signature')

@section('content')

{{-- Scoped to this page. Everything borrowed below (.card, .empty-state,
     .field-hint, .status-badge, the styled ::file-selector-button) is already
     global; this only adds the signature plate and the drop target.

     The plate is white and stays white in both themes on purpose: a signature
     is black ink scanned off white paper, and on a dark surface it disappears.
     It is also exactly what the Confirmation of Correction prints it onto, so
     this is a preview of the document, not a preview of the page. --}}
<style>
    .sig-card { max-width: 620px; }

    .sig-plate {
        display: flex; align-items: center; justify-content: center;
        min-height: 120px; padding: var(--space-5);
        background: #fff;
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-md);
    }
    .sig-plate img { max-height: 90px; max-width: 100%; display: block; }

    .sig-onfile { margin-bottom: var(--space-6); }
    .sig-onfile-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: var(--space-3); flex-wrap: wrap; margin-bottom: var(--space-3);
    }
    .sig-onfile-head h3 { margin: 0; font-size: var(--text-md); }
    .sig-filename { font-size: var(--text-sm); color: var(--text-grey); }

    /* The whole block is the label for the file input, so the click target is
       the box rather than a 90px button inside it. The input stays in the DOM
       and keeps working without JavaScript -- it is only visually replaced. */
    .sig-drop {
        display: block; text-align: center; cursor: pointer;
        padding: var(--space-8) var(--space-6);
        border: 1.5px dashed var(--border-strong);
        border-radius: var(--radius-md);
        background: var(--surface-sunken);
        transition: border-color var(--duration-base) var(--ease),
                    background-color var(--duration-base) var(--ease);
    }
    .sig-drop:hover { border-color: var(--navy); background: var(--light-blue-bg); }
    .sig-drop:focus-within { border-color: var(--navy); outline: 2px solid var(--accent-solid); outline-offset: 2px; }
    .sig-drop input[type="file"] { display: none; }

    .sig-drop-icon { color: var(--text-grey); margin-bottom: var(--space-3); }
    .sig-drop-title { display: block; font-weight: var(--weight-semi); color: var(--navy); }
    .sig-drop-note { display: block; margin-top: var(--space-2); font-size: var(--text-sm); color: var(--text-grey); }

    /* Filled in by the script once a file is chosen -- without it, hiding the
       native input would hide "No file chosen" too and leave no feedback. */
    .sig-chosen { margin-top: var(--space-5); }
    .sig-chosen[hidden] { display: none; }
    .sig-chosen-label {
        display: block; margin-bottom: var(--space-2);
        font-size: var(--text-sm); font-weight: var(--weight-semi); color: var(--text-dark);
    }

    .sig-actions { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--space-6); }
    .sig-actions button { margin: 0; }
</style>

<div class="card-container-inline">
    <div class="card card-wide sig-card">
        <h2>My Signature</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            When you approve a Hardbound Submission as Supervisor or as Chairman of the Viva
            Voce Examination, this image is stamped into your block on the Confirmation of
            Correction to Thesis (UTP/CGS/017A) together with that day's date.
        </p>

        @if ($signature)
            <div class="sig-onfile">
                <div class="sig-onfile-head">
                    <h3>On file <span class="status-badge approved">Ready</span></h3>
                    <span class="sig-filename">
                        {{ $signature->original_name }} · uploaded {{ $signature->updated_at->format('j M Y, g:ia') }}
                    </span>
                </div>
                <div class="sig-plate">
                    <img src="{{ route('hardbound.signature.image') }}?v={{ $signature->updated_at->timestamp }}"
                         alt="Your signature as it appears on the form">
                </div>
            </div>
        @else
            <div class="empty-state" style="margin-bottom: var(--space-6);">
                <b>No signature on file.</b><br>
                You cannot approve a Hardbound Submission until one is uploaded.
            </div>
        @endif

        <form method="POST" action="{{ route('hardbound.signature.store') }}" enctype="multipart/form-data">
            @csrf

            <label class="sig-drop" for="signature">
                <span class="sig-drop-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </span>
                <span class="sig-drop-title">{{ $signature ? 'Choose a replacement' : 'Choose your signature image' }}</span>
                <span class="sig-drop-note">
                    PNG or JPG, under 1 MB. Sign on white paper and photograph or scan it, or
                    export from a signature app. A transparent PNG looks best on the form.
                </span>
                <input type="file" name="signature" id="signature" accept="image/png,image/jpeg" required>
            </label>
            @error('signature') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Shown once something is picked. Checking the scan is the right
                 way up before it is stamped onto a form is worth the six lines
                 this costs; the alternative is saving it to find out. --}}
            <div class="sig-chosen" hidden>
                <span class="sig-chosen-label"></span>
                <div class="sig-plate"><img alt="The signature you are about to save"></div>
            </div>

            <div class="sig-actions">
                <button type="submit">{{ $signature ? 'Replace Signature' : 'Save Signature' }}</button>
                <span class="field-hint" style="margin: 0;">Replaceable at any time.</span>
            </div>
        </form>
    </div>
</div>

<script @cspNonce>
    /* FileReader rather than URL.createObjectURL: that returns a blob: URL,
       and this app's img-src is "'self' data:" with no blob:, so the preview
       would be refused by the Content-Security-Policy and silently never
       appear. A data: URI is already allowed. */
    (function () {
        var input = document.getElementById('signature');
        var chosen = document.querySelector('.sig-chosen');
        if (! input || ! chosen) return;

        var label = chosen.querySelector('.sig-chosen-label');
        var preview = chosen.querySelector('img');

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            if (! file) {
                chosen.hidden = true;
                return;
            }

            var reader = new FileReader();

            reader.addEventListener('load', function () {
                label.textContent = 'About to save: ' + file.name;
                preview.src = reader.result;
                chosen.hidden = false;
            });

            // An unreadable file is the browser's problem to report on submit,
            // not something to block the upload over.
            reader.addEventListener('error', function () { chosen.hidden = true; });

            reader.readAsDataURL(file);
        });
    })();
</script>
@endsection
