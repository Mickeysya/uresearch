@extends('core::layouts.app')

@section('title', 'Hardbound Submission — ' . $stage->queueTitle())

@section('content')
    <p class="queue-meta">
        @if ($stage->key === 'supervisor')
            Approving certifies the thesis has been corrected as required and stamps your
            signature and today's date into the <b>Supervisor</b> block of the Confirmation of
            Correction to Thesis (UTP/CGS/017A), then sends it to the viva chairman.
        @elseif ($stage->key === 'chair')
            Approving stamps your signature and today's date into the <b>Chairman, Viva Voce
            Examination</b> block of the Confirmation, then sends the pack to CGS.
        @else
            Approving accepts the pack and issues the student's acknowledgement receipt. The
            Examiner block on the Confirmation is signed on paper.
        @endif
        Rejecting returns the submission to the student for correction, and needs comments
        saying what to fix.
        @if ($stage->key !== 'cgs_review')<a href="{{ route('hardbound.signature') }}">Check the signature on file &rarr;</a>@endif
    </p>

    @include('core::partials.queue', [
        'moduleLabel' => 'Hardbound Submission',
        'decideRoute' => 'hardbound.decide',
        'detailView' => 'jason::hardbound._detail',
    ])
@endsection
