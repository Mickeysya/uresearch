@extends('core::layouts.app')

@section('title', 'Supervision — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Supervision',
        'decideRoute' => 'supervision.decide',
        'detailView' => 'nureen::supervision._detail',
    ])
@endsection
