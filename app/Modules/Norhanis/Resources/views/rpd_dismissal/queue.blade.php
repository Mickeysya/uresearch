@extends('core::layouts.app')

@section('title', 'RPD Dismissals: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'RPD Dismissal',
        'decideRoute' => 'rpd-dismissal.decide',
        'bulk' => false, // decide() does more than the engine; Core's bulk path would skip it
        'detailView' => 'norhanis::rpd_dismissal._detail',
    ])
@endsection
