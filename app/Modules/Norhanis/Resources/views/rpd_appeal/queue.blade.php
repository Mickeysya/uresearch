@extends('core::layouts.app')

@section('title', 'RPD Appeals: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'RPD Extension Appeal',
        'decideRoute' => 'rpd-appeal.decide',
        'bulk' => false, // decide() does more than the engine; Core's bulk path would skip it
        'detailView' => 'norhanis::rpd_appeal._detail',
    ])
@endsection
