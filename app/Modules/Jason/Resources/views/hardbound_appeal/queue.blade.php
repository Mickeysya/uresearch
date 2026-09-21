@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission: ' . $stage->queueTitle())

@section('content')
    {{-- Into the partial, not above it: see the note in hardbound/queue. --}}
    @php
        $intro = $stage->key === 'cgs_review'
            ? 'Approving compiles the Dean PFR report from the appeal and the original submission, '
                .'and sends it to the Senior Executive for a ruling. Your remarks become the '
                .'recommendation in that report, so they are required.'
            : 'Upholding an appeal lets the student resubmit the thesis it was filed against.';
    @endphp

    @include('core::partials.queue', [
        'moduleLabel' => 'Appeal Hardbound Submission',
        'decideRoute' => 'hardbound-appeal.decide',
        'detailView' => 'jason::hardbound_appeal._detail',
        'intro' => $intro,
    ])
@endsection
