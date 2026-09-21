@extends('core::layouts.app')

@section('title', 'Hardbound Submission: ' . $stage->queueTitle())

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

    {{-- Passed INTO the partial, not written above it: the partial renders
         the page header, so anything printed before the include lands above
         the page title and the screen reads as though it has no heading.
         The conditional is PHP rather than Blade because it has to survive
         being a single value. --}}
    @php
        $intro = match ($stage->key) {
            'supervisor' => 'Approving certifies the thesis has been corrected as required and stamps your '
                .'signature and today\'s date into the <b>Supervisor</b> block of the Confirmation of '
                .'Correction to Thesis (UTP/CGS/017A), then sends it to the viva chairman.',
            'chair' => 'Approving stamps your signature and today\'s date into the <b>Chairman, Viva Voce '
                .'Examination</b> block of the Confirmation, then sends the pack to CGS.',
            default => 'Approving accepts the pack and issues the student\'s acknowledgement receipt. The '
                .'Examiner block on the Confirmation is signed on paper.',
        };

        $intro .= ' Rejecting returns the submission to the student for correction, and needs comments saying what to fix.';

        if ($stage->key !== 'cgs_review') {
            $intro .= ' <a href="'.route('hardbound.signature').'">Check the signature on file &rarr;</a>';
        }
    @endphp

    @include('core::partials.queue', [
        'moduleLabel' => 'Hardbound Submission',
        'decideRoute' => 'hardbound.decide',
        'detailView' => 'jason::hardbound._detail',
        'intro' => $intro,
    ])
@endsection
