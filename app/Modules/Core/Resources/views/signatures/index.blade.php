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
            <section class="card card-wide sig-card {{ $onFile ? 'is-on-file' : '' }}" id="{{ $sig['key'] }}" aria-labelledby="sig-{{ $sig['key'] }}-title">
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

                        <div class="sig-buttons">
                            <button type="submit" data-sig-submit disabled
                                    data-tip="Saves this image for {{ $sig['title'] }} only">{{ $onFile ? 'Replace signature' : 'Upload signature' }}</button>
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

@push('scripts')
<script @cspNonce>
    // Show the chosen image before it is saved, and only enable the button
    // once there is something to save. readAsDataURL, not createObjectURL:
    // the CSP's img-src allows data: but not blob:.
    document.querySelectorAll('[data-sig-form]').forEach(function (form) {
        var input = form.querySelector('[data-sig-input]');
        var img = form.querySelector('[data-sig-img]');
        var empty = form.querySelector('[data-sig-empty]');
        var caption = form.querySelector('[data-sig-caption]');
        var name = form.querySelector('[data-sig-name]');
        var submit = form.querySelector('[data-sig-submit]');
        var drop = form.querySelector('.sig-drop');

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            submit.disabled = ! file;
            if (! file) return;
            name.textContent = file.name;
            var reader = new FileReader();
            reader.onload = function () {
                img.src = reader.result;
                img.hidden = false;
                empty.hidden = true;
                caption.textContent = 'Preview: not saved until you press ' + submit.textContent.trim();
                form.closest('.sig-card').classList.add('is-pending');
            };
            reader.readAsDataURL(file);
        });

        ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.remove('is-over'); }); });
    });

    document.querySelectorAll('.sig-remove-btn').forEach(function (b) {
        b.addEventListener('click', function (e) {
            if (! confirm('Remove this signature?')) e.preventDefault();
        });
    });
</script>
@endpush
@endsection
