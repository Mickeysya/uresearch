@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission — ' . $stage->queueTitle())

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

    @if ($stage->key === 'cgs_review')
        <p class="queue-meta">
            Approving compiles the Dean PFR report from the appeal and the original
            submission, and sends it to the Senior Executive for a ruling.
            <b>Your remarks become the recommendation printed in that report and are
            required</b> — despite the label on the box below.
        </p>
    @else
        <p class="queue-meta">
            Upholding an appeal lets the student resubmit the thesis it was filed against.
        </p>
    @endif

    @include('core::partials.queue', [
        'moduleLabel' => 'Appeal Hardbound Submission',
        'decideRoute' => 'hardbound-appeal.decide',
        'detailView' => 'jason::hardbound_appeal._detail',
    ])
@endsection
