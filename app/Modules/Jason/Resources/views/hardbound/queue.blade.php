@extends('core::layouts.app')

@section('title', 'Hardbound Submission — ' . $stage->queueTitle())

@section('content')
    <p class="queue-meta">
        @if ($stage->key === 'supervisor')
            Approving confirms the corrections listed on the Confirmation of Correction to
            Thesis and stamps your signature and today's date onto it, then sends it to the
            Chair.
        @elseif ($stage->key === 'chair')
            Approving endorses the supervisor's confirmation, stamps your signature and
            today's date onto the Confirmation, and sends it to CGS.
        @else
            Approving accepts the pack, stamps your signature onto the Confirmation, and
            issues the student's acknowledgement receipt.
        @endif
        Rejecting returns the submission to the student for correction, and needs comments
        saying what to fix.
        <a href="{{ route('hardbound.signature') }}">Check the signature on file &rarr;</a>
    </p>

    @include('core::partials.queue', [
        'moduleLabel' => 'Hardbound Submission',
        'decideRoute' => 'hardbound.decide',
        'detailView' => 'jason::hardbound._detail',
    ])
@endsection
