@extends('core::layouts.app')

@section('title', 'Appeal Hardbound Submission: ' . $stage->queueTitle())

@section('content')
    @if ($stage->key === 'cgs_review')
        <p class="queue-meta">
            Approving compiles the Dean PFR report from the appeal and the original
            submission, and sends it to the Senior Executive for a ruling. Your remarks
            become the recommendation in that report, so they are required.
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
