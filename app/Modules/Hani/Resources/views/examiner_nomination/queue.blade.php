@extends('core::layouts.app')

@section('title', 'Examiner Nomination: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Examiner Nomination',
        'decideRoute' => 'examiner-nomination.decide',
        'detailView' => 'hani::examiner_nomination._detail',
        'tools' => 'hani::examiner_nomination._tools',
    ] + ($stage->key === 'academic_exec' ? [
        'intro' => 'Nominations at this stage go to the DAC. Compile them, then record the DAC\'s decision on <a href="'.e(route('examiner-nomination.dac')).'">DAC Compilation</a>. Approve and Reject here are refused.',
    ] : []) + ($canReturn ? [
        // Only the Senior Director and the Dean get this. Approving sends the
        // list on; sending it back drops it to the Academic Executive, who
        // picks again -- and the chain replays from there. See
        // WorkflowEngine::returnTo().
        'returnRoute' => 'examiner-nomination.return',
        'returnLabel' => 'Send back to the department',
    ] : []))
@endsection
