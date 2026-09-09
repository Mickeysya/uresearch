@extends('core::layouts.app')

@section('title', 'Travel — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Travel',
        'decideRoute' => 'travel.decide',
        'detailView' => 'norhanis::travel._detail',
    ])
@endsection
