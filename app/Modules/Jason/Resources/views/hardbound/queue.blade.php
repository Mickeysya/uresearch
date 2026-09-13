@extends('core::layouts.app')

@section('title', 'Hardbound Submission — ' . $stage->queueTitle())

@section('content')
    @if ($stage->key === 'cgs_review')
        <p class="queue-meta">
            Forwarding sends the submission to the Senior Executive CGS. Rejecting returns it
            to the student for correction, and needs comments saying what to fix.
        </p>
    @endif

    @include('core::partials.queue', [
        'moduleLabel' => 'Hardbound Submission',
        'decideRoute' => 'hardbound.decide',
        'detailView' => 'jason::hardbound._detail',
    ])
@endsection
