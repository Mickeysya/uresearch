@extends('core::layouts.app')

@section('title', 'Student Claims — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Student Claims',
        'decideRoute' => 'claims.decide',
        'detailView' => 'norhanis::claims._detail',
    ])
@endsection
