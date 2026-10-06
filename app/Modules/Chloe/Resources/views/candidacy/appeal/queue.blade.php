@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal: ' . $stage->queueTitle())

{{--
    No bulk decide: CandidacyAppealWorkflow implements DecidesOneAtATime.
    CandidacyAppealController::decide() does more than call the engine. A
    rejection blocks every future appeal and the Dean's approval moves the
    expiry date, and Core's bulk decide would skip both.
--}}
@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Study Candidacy Appeal',
        'decideRoute' => 'candidacy-appeal.decide',
        'detailView' => 'chloe::candidacy.appeal._detail',
        'allowReturn' => true,
        'intro' => '<b>Return</b> sends an appeal back to the student to revise; it comes back to you when they resubmit. <b>Reject</b> is final and blocks any further appeal for that candidacy.',
    ])
@endsection
