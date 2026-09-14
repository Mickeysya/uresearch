@extends('core::layouts.app')

@section('title', 'Publication — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Publication',
        'decideRoute' => 'publication.decide',
        'detailView' => 'norhanis::publication._detail',
    ])
@endsection
