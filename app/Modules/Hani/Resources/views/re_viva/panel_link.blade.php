@extends('core::layouts.guest')

{{-- The examiner's documents, after the sign-in (ReVivaPanelLinkController).
     Plain links and no scripts, so it works under the ContentSecurityPolicy. --}}
@section('content')
<div class="card-container">
    <div class="card" style="max-width: 640px; width: 100%">
        <h2>Corrected thesis for re-evaluation</h2>
        <p class="fstep-hint">Centre for Graduate Studies, Universiti Teknologi PETRONAS</p>
        <div class="card-divider"></div>

        <p>Dear {{ $examiner->name }},</p>
        <p>Below are the corrected thesis and the Summary of Corrections of <b>{{ $student->name }}</b>@if ($detail->cycle_number > 1) (re-viva round {{ $detail->cycle_number }})@endif.</p>

        <ul class="doc-list">
            @forelse ($documents as $doc)
                <li><a href="{{ $doc['url'] }}">{{ $doc['label'] }}: {{ $doc['name'] }}</a></li>
            @empty
                <li>No documents are available. Please contact the Centre for Graduate Studies.</li>
            @endforelse
        </ul>

        <p class="fstep-hint">This link works until {{ $expires->format('j F Y') }}. Please return the Thesis Re-evaluation Report (UTP/CGS/024) to the contacts named in your letter.</p>

        <form method="POST" action="{{ $signOut }}">
            @csrf
            <input type="hidden" name="step" value="sign_out">
            <button type="submit" class="btn-secondary">Sign out</button>
        </form>
    </div>
</div>
@endsection
