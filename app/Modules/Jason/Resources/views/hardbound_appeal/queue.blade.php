@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission: ' . $stage->queueTitle())

@section('content')
    @if ($errors->any())
        {{-- Core's layout flashes session messages but never renders the
             validation error bag, and the shared queue partial has no slot for
             it, so a failed rule would bounce the reviewer back to an unchanged
             page with no explanation. --}}
        <div class="message-error">
            @foreach ($errors->all() as $message)
                <p style="margin: 0;">{{ $message }}</p>
            @endforeach
        </div>
    @endif

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
