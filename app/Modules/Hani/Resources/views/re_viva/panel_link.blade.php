@extends('core::layouts.guest')

{{-- The examiner's page from their correction letter. No login, no
     scripts: plain links, so it works under the ContentSecurityPolicy. --}}
@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Corrected thesis for re-evaluation"
        subtitle="Centre for Graduate Studies, Universiti Teknologi PETRONAS" />

    <div class="card card-wide">
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
    </div>
</div>
@endsection
