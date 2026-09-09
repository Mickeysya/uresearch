@extends('core::layouts.app')

@section('title', 'Examiner Nomination — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Examiner Nomination',
        'decideRoute' => 'examiner-nomination.decide',
        'detailView' => 'hani::examiner_nomination._detail',
    ])
@endsection
