@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission: ' . $stage->queueTitle())

@section('content')
    {{-- Core's layout flashes session messages but never renders the
         validation error bag, and nothing may print above the page header,
         so a failed rule is folded into the intro the partial renders. --}}
    @php
        $errorLine = $errors->any()
            ? '<b class="field-error">'.e(implode(' ', $errors->all())).'</b> '
            : '';
    @endphp

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
        'intro' => $errorLine.$intro,
    ])
@endsection
