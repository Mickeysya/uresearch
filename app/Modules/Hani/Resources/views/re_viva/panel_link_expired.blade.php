@extends('core::layouts.guest')

{{-- An examiner's re-viva link that was correctly signed but has expired
     (Hani\Http\Middleware\ValidPanelLink). No login, no scripts. --}}
@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="This link has expired"
        subtitle="Centre for Graduate Studies, Universiti Teknologi PETRONAS" />

    <div class="card card-wide">
        <p>The link to the corrected thesis worked until {{ $expired->format('j F Y') }}, so the documents can no longer be opened from it.</p>
        <p>Please ask the Centre for Graduate Studies to resend your letter. The new email carries a fresh link to the same documents:</p>

        <ul class="doc-list">
            @foreach ($contacts as $contact)
                <li>{{ $contact['name'] }}, {{ $contact['role'] }}: <a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@if (! empty($contact['phone'])), {{ $contact['phone'] }}@endif</li>
            @endforeach
        </ul>
    </div>
</div>
@endsection
