@extends('core::layouts.app')

@section('title', 'RPD Dismissals — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'RPD Dismissal',
        'decideRoute' => 'rpd-dismissal.decide',
        'detailView' => 'norhanis::rpd_dismissal._detail',
    ])
@endsection
