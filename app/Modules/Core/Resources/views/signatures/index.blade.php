@extends('core::layouts.app')

@section('title', 'My Signatures')

{{--
    One page for every signature image this person keeps. Each section comes
    from the module that stamps it (Contracts\SuppliesSignature): its title,
    what it goes on, whether an approval is blocked without it, and the
    module's own upload / remove routes, which send the user back here
    because the form carries return=/signatures.
--}}
@php
    $required = collect($signatures)->where('required', true);
    $missing = $required->filter(fn ($s) => $s['imageUrl'] === null);
@endphp

@section('content')
<div class="card-container-inline sig-page">
    <x-core::page-header title="My Signatures" :back="$return" :back-history="false"
        subtitle="Each kind of document has its own signature. Upload the ones marked Required; the others are optional." />

    {{-- Chloe's module flashes wca_status; everyone else uses status, which
         the layout's flash partial already shows. --}}
    @if (session('wca_status'))
        <p class="message-success">{{ session('wca_status') }}</p>
    @endif
    @error('signature')
        <p class="message-error">{{ $message }}</p>
    @enderror

    @if ($required->isNotEmpty())
        <p class="sig-summary {{ $missing->isEmpty() ? 'is-done' : 'is-todo' }}" role="status">
            <span class="sig-summary-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => $missing->isEmpty() ? 'check' : 'warning'])</span>
            @if ($missing->isEmpty())
                All {{ $required->count() }} required {{ Str::plural('signature', $required->count()) }} on file. You can approve everything that needs one.
            @else
                <span>
                    {{ $missing->count() }} of {{ $required->count() }} required {{ Str::plural('signature', $required->count()) }} missing:
                    {!! $missing->map(fn ($m) => '<a href="#'.e($m['key']).'">'.e($m['title']).'</a>')->implode(', ') !!}.
                    Approvals that need {{ $missing->count() === 1 ? 'it' : 'them' }} are blocked until you upload.
                </span>
            @endif
        </p>
    @endif

    <div class="sig-list">
        @foreach ($signatures as $sig)
            @php $onFile = $sig['imageUrl'] !== null; $fileId = 'sig-'.$sig['key'].'-file'; @endphp
            <section class="card card-wide sig-card {{ $onFile ? 'is-on-file' : '' }}" id="{{ $sig['key'] }}" data-sig-title="{{ $sig['title'] }}" aria-labelledby="sig-{{ $sig['key'] }}-title">
                <header class="sig-head">
                    <h3 id="sig-{{ $sig['key'] }}-title">{{ $sig['title'] }}</h3>
                    <div class="sig-badges">
                        <span class="status-badge {{ $sig['required'] ? 'pending' : 'draft' }}"
                              data-tip="{{ $sig['required'] ? 'You cannot approve these without it.' : 'Approvals go through without it.' }}">
                            {{ $sig['required'] ? 'Required' : 'Optional' }}
                        </span>
                        <span class="status-badge {{ $onFile ? 'approved' : ($sig['required'] ? 'rejected' : 'draft') }}"
                              data-tip="{{ $onFile && $sig['updatedAt'] ? 'Uploaded '.$sig['updatedAt']->format('j M Y, g:ia') : 'Nothing uploaded yet' }}">
                            {{ $onFile ? 'On file' : 'Not uploaded' }}
                        </span>
                    </div>
                </header>
                <p class="sig-used-for">{{ $sig['usedFor'] }}</p>

                <form method="POST" action="{{ route($sig['storeRoute']) }}" enctype="multipart/form-data" class="sig-body" data-sig-form>
                    @csrf
                    <input type="hidden" name="return" value="{{ $return ?? '/signatures' }}">

                    <figure class="sig-preview {{ $onFile ? '' : 'is-empty' }}">
                        <img src="{{ $onFile ? $sig['imageUrl'].(str_contains($sig['imageUrl'], '?') ? '&' : '?').'v='.$sig['updatedAt']?->timestamp : '' }}"
                             alt="Your signature for {{ $sig['title'] }}" data-sig-img @if (! $onFile) hidden @endif>
                        <span class="sig-preview-empty" data-sig-empty @if ($onFile) hidden @endif>No signature yet</span>
                        <figcaption data-sig-caption>
                            {{ $onFile && $sig['updatedAt'] ? 'On file since '.$sig['updatedAt']->format('j M Y') : 'Your signature will appear here' }}
                        </figcaption>
                    </figure>

                    <div class="sig-upload">
                        <label class="sig-drop" for="{{ $fileId }}" data-tip="PNG or JPG. Click to choose, or drag an image here.">
                            <span class="sig-drop-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'upload'])</span>
                            <span class="sig-drop-text">
                                <b data-sig-name>{{ $onFile ? 'Choose a new image to replace it' : 'Choose your signature image' }}</b>
                                <span>or drag it here</span>
                            </span>
                            <input type="file" name="signature" id="{{ $fileId }}" accept="image/png,image/jpeg" required data-sig-input>
                        </label>
                        <p class="field-hint">{{ $sig['hint'] }}</p>

                        {{-- Clean-up runs in the browser on the chosen image before it is
                             sent, so no module has to process images. Shown once a file is
                             chosen. --}}
                        <fieldset class="sig-clean" data-sig-clean hidden>
                            <legend>Clean-up</legend>
                            <label class="sig-check" data-tip="Turns the paper behind your signature transparent, so only the ink is stamped">
                                <input type="checkbox" data-sig-opt="bg" checked> Remove background
                            </label>
                            <label class="sig-check" data-tip="Cuts away the empty space around the ink">
                                <input type="checkbox" data-sig-opt="trim" checked> Crop to the signature
                            </label>
                            <label class="sig-range" data-tip="Raise it if shadows or grey paper are left behind; lower it if faint strokes disappear">
                                <span>Background strength</span>
                                <input type="range" min="10" max="120" value="45" data-sig-opt="level">
                            </label>
                        </fieldset>

                        <div class="sig-buttons">
                            <button type="submit" data-sig-submit disabled
                                    data-tip="Saves this image for {{ $sig['title'] }} only">{{ $onFile ? 'Replace signature' : 'Upload signature' }}</button>
                            <button type="button" class="btn-secondary" data-sig-doc @if (! $onFile) disabled @endif
                                    data-tip="See it on a sample page, the way it will be stamped">Preview on a document</button>
                            @if ($onFile && $sig['destroyRoute'])
                                <button type="submit" form="sig-{{ $sig['key'] }}-remove" class="btn-secondary sig-remove-btn"
                                        data-tip="{{ $sig['required'] ? 'Approvals that need it will be blocked until you upload again' : 'Your stamp becomes text only' }}">Remove</button>
                            @endif
                        </div>
                    </div>
                </form>

                @if ($onFile && $sig['destroyRoute'])
                    <form method="POST" action="{{ route($sig['destroyRoute']) }}" id="sig-{{ $sig['key'] }}-remove" hidden>
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="return" value="{{ $return ?? '/signatures' }}">
                    </form>
                @endif
            </section>
        @endforeach
    </div>
</div>

{{-- A sample page, not the real form: what matters is how the ink sits on a
     signature line at the size it will be printed. --}}
<dialog class="sig-doc-modal" id="sig-doc" aria-labelledby="sig-doc-title">
    <div class="sig-doc-bar">
        <h3 id="sig-doc-title">Preview on a document</h3>
        <button type="button" class="btn-secondary" data-sig-doc-close>Close</button>
    </div>
    <div class="sig-doc-page">
        <p class="sig-doc-org">UNIVERSITI TEKNOLOGI PETRONAS · CENTRE FOR GRADUATE STUDIES</p>
        <p class="sig-doc-name" data-sig-doc-name></p>
        <div class="sig-doc-lines" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="sig-doc-block">
            <img alt="Your signature, as stamped" data-sig-doc-img>
            <span class="sig-doc-rule"></span>
            <b>{{ auth()->user()->name }}</b>
            <span>Date: {{ now()->format('j F Y') }}</span>
        </div>
        <p class="sig-doc-note">Sample only. The real document stamps this image at this size above your name.</p>
    </div>
</dialog>

@push('scripts')
<script @cspNonce>
(function () {
    // Every step runs on a canvas in the browser: the cleaned PNG replaces the
    // chosen file in the input, so the module receives an image that is
    // already transparent and cropped. readAsDataURL, not createObjectURL:
    // the CSP's img-src allows data: but not blob:.
    var MIN_W = 200, MIN_H = 60, MAX_W = 1200, PAD = 12;

    function clean(img, opts) {
        var c = document.createElement('canvas');
        c.width = img.naturalWidth; c.height = img.naturalHeight;
        var ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0);
        var data = ctx.getImageData(0, 0, c.width, c.height), px = data.data;
        var minX = c.width, minY = c.height, maxX = -1, maxY = -1;

        // How bright is THIS photo's paper? Most of a signature photo is
        // paper, so its median brightness is the paper's. Ink is whatever is
        // clearly darker than that -- a fixed cut-off mistook the shadowed
        // corner of a phone photo for ink.
        var hist = new Array(256).fill(0), n = 0;
        for (var j = 0; j < px.length; j += 4) {
            if (px[j + 3] < 20) continue;
            hist[Math.round(0.299 * px[j] + 0.587 * px[j + 1] + 0.114 * px[j + 2])]++; n++;
        }
        var acc = 0, paperLevel = 255;
        for (var k = 0; k < 256; k++) { acc += hist[k]; if (acc >= n / 2) { paperLevel = k; break; } }
        var cut = paperLevel - opts.level;   // the slider is how far below the paper ink must be

        for (var i = 0; i < px.length; i += 4) {
            var light = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
            var paper = px[i + 3] < 20 || light >= cut;
            if (opts.bg && paper) {
                px[i + 3] = 0;
            } else if (opts.bg) {
                // Soften the edge between ink and paper instead of a hard step.
                px[i + 3] = Math.min(px[i + 3], Math.round(255 * Math.min(1, (cut - light) / 30)));
            }
            if (! paper) {
                var p = i / 4, x = p % c.width, y = (p - x) / c.width;
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; if (y > maxY) maxY = y;
            }
        }
        ctx.putImageData(data, 0, 0);

        if (maxX < 0) return null; // nothing that looks like ink

        var sx = 0, sy = 0, sw = c.width, sh = c.height;
        if (opts.trim) {
            sx = Math.max(0, minX - PAD); sy = Math.max(0, minY - PAD);
            sw = Math.min(c.width, maxX + PAD + 1) - sx; sh = Math.min(c.height, maxY + PAD + 1) - sy;
        }

        // At least 200 x 60 (the candidacy module's minimum), at most 1200 wide.
        var scale = Math.min(MAX_W / sw, Math.max(1, MIN_W / sw, MIN_H / sh));
        var out = document.createElement('canvas');
        out.width = Math.max(MIN_W, Math.round(sw * scale));
        out.height = Math.max(MIN_H, Math.round(sh * scale));
        var o = out.getContext('2d');
        if (! opts.bg) { o.fillStyle = '#fff'; o.fillRect(0, 0, out.width, out.height); }
        o.drawImage(c, sx, sy, sw, sh, (out.width - sw * scale) / 2, (out.height - sh * scale) / 2, sw * scale, sh * scale);
        return out;
    }

    var modal = document.getElementById('sig-doc');
    modal.querySelector('[data-sig-doc-close]').addEventListener('click', function () { modal.close(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });

    document.querySelectorAll('[data-sig-form]').forEach(function (form) {
        var card = form.closest('.sig-card');
        var input = form.querySelector('[data-sig-input]');
        var img = form.querySelector('[data-sig-img]');
        var empty = form.querySelector('[data-sig-empty]');
        var caption = form.querySelector('[data-sig-caption]');
        var name = form.querySelector('[data-sig-name]');
        var submit = form.querySelector('[data-sig-submit]');
        var docBtn = form.querySelector('[data-sig-doc]');
        var panel = form.querySelector('[data-sig-clean]');
        var drop = form.querySelector('.sig-drop');
        var source = null, sourceName = '', busy = false;

        function opts() {
            return {
                bg: panel.querySelector('[data-sig-opt=bg]').checked,
                trim: panel.querySelector('[data-sig-opt=trim]').checked,
                level: +panel.querySelector('[data-sig-opt=level]').value,
            };
        }

        function render() {
            if (! source) return;
            var canvas = clean(source, opts());
            if (! canvas) {
                caption.textContent = 'No ink found. Lower the background strength, or choose a clearer photo.';
                submit.disabled = true;
                return;
            }
            img.src = canvas.toDataURL('image/png');
            img.hidden = false; empty.hidden = true;
            card.classList.add('is-pending');
            caption.textContent = 'Preview, ' + canvas.width + ' x ' + canvas.height + ' px: not saved until you press ' + submit.textContent.trim();
            busy = true;
            canvas.toBlob(function (blob) {
                var file = new File([blob], sourceName.replace(/\.[^.]+$/, '') + '-signature.png', { type: 'image/png' });
                var dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;   // the cleaned image is what gets uploaded
                busy = false;
                submit.disabled = false;
                docBtn.disabled = false;
            }, 'image/png');
        }

        input.addEventListener('change', function () {
            if (busy) return;   // our own swap of input.files
            var file = input.files && input.files[0];
            submit.disabled = true;
            if (! file) return;
            sourceName = file.name;
            name.textContent = file.name;
            var reader = new FileReader();
            reader.onload = function () {
                var im = new Image();
                im.onload = function () { source = im; panel.hidden = false; render(); };
                im.src = reader.result;
            };
            reader.readAsDataURL(file);
        });

        panel.addEventListener('input', render);

        docBtn.addEventListener('click', function () {
            modal.querySelector('[data-sig-doc-img]').src = img.src;
            modal.querySelector('[data-sig-doc-name]').textContent = card.dataset.sigTitle;
            modal.showModal();
        });

        ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.remove('is-over'); }); });
    });

    document.querySelectorAll('.sig-remove-btn').forEach(function (b) {
        b.addEventListener('click', function (e) {
            if (! confirm('Remove this signature?')) e.preventDefault();
        });
    });
})();
</script>
@endpush
@endsection
