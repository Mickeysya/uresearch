@extends('core::layouts.app')

@section('title', 'My Signature')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="My Signature" subtitle="Your signature is stamped on every appeal you approve." :back="route('candidacy.portal.queue')" :back-history="false">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <section class="wca-card" aria-labelledby="wca-h-sig">
            <h3 id="wca-h-sig">{{ $profile?->hasSignature() ? 'Your signature on file' : 'No signature on file' }}</h3>
            @if ($profile?->hasSignature())
                <div class="wca-sig-preview"><img class="wca-sig-img" src="{{ route('candidacy.signature.image', ['v' => $profile->signature_updated_at?->timestamp]) }}" alt="Your signature"></div>
                <p class="wca-muted wca-small">Saved {{ $profile->signature_updated_at?->setTimezone('Asia/Kuala_Lumpur')->format('j M Y, H:i') }}. Appeals you already approved keep the copy of the signature you had at that time.</p>
            @else
                <p>{{ $required ? 'You must upload a signature before you can approve an appeal.' : 'A signature is optional for your role. Without one, your stamp is text only.' }}</p>
            @endif
        </section>

        <section class="wca-card" aria-labelledby="wca-h-up">
            <h3 id="wca-h-up">{{ $profile?->hasSignature() ? 'Replace your signature' : 'Upload your signature' }}</h3>
            <form method="POST" action="{{ route('candidacy.signature.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="wca-field"><label for="wca-sig-file">Signature image (PNG or JPG)</label><input id="wca-sig-file" class="wca-input" type="file" name="signature" accept="image/png,image/jpeg"></div>
                <div class="wca-sig-preview wca-hidden" id="wca-sig-new"><img class="wca-sig-img" id="wca-sig-new-img" alt="Preview of the chosen signature"></div>
                <p class="wca-muted wca-small">At most 1 MB, at least 200 x 60 pixels. It is resized to fit 600 x 200 pixels. A white or transparent background works best.</p>
                <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Save signature</button></div>
            </form>
            @if ($profile?->hasSignature())
                <form method="POST" action="{{ route('candidacy.signature.destroy') }}">@csrf @method('DELETE')<div class="wca-actions"><button type="submit" class="wca-btn">Remove my signature</button></div></form>
            @endif
        </section>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])
@endsection
