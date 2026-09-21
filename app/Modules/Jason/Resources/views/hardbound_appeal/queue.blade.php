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

        // Core's layout flashes session messages but never renders the
        // validation error bag, and the shared queue partial has no slot
        // for it, so a failed rule would bounce the reviewer back to an
        // unchanged page with no explanation. Prepended onto $intro rather
        // than a separate block, since the partial only takes the one.
        if ($errors->any()) {
            $intro = '<div class="message-error">'
                .collect($errors->all())->map(fn ($message) => '<p style="margin: 0;">'.e($message).'</p>')->implode('')
                .'</div>'
                .$intro;
        }
    @endphp

    @include('core::partials.queue', [
        'moduleLabel' => 'Appeal Hardbound Submission',
        'decideRoute' => 'hardbound-appeal.decide',
        'detailView' => 'jason::hardbound_appeal._detail',
        'intro' => $intro,
    ])
@endsection
