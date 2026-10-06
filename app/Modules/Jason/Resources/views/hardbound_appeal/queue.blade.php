@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission: ' . $stage->queueTitle())

@section('content')
    {{-- Into the partial, not above it: see the note in hardbound/queue. --}}
    @php
        $intro = $stage->key === 'cgs_review'
            ? 'Approving records that CGS has the memo and tells the candidate to wait for '
                .'further notification. It does not grant the extension: the memo goes to the '
                .'Dean of Postgraduate and Research, who signs it, and CGS emails the candidate '
                .'the outcome. Rejecting ends the appeal and needs remarks.'
            : 'Endorsing stamps your signature and today\'s date onto the candidate\'s memo and '
                .'passes it on. Rejecting ends the appeal and needs remarks saying why. '
                .'<a href="'.route('hardbound.signature').'">Check the signature on file &rarr;</a>';
    @endphp

    @include('core::partials.queue', [
        'moduleLabel' => 'Appeal Hardbound Submission',
        'decideRoute' => 'hardbound-appeal.decide',
        'detailView' => 'jason::hardbound_appeal._detail',
        'intro' => $intro,
    ])
@endsection
