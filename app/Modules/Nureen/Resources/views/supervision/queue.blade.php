@extends('core::layouts.app')

@section('title', 'Supervision: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Supervision',
        'decideRoute' => 'supervision.decide',
        'bulk' => false, // decide() does more than the engine; Core's bulk path would skip it
        'detailView' => 'nureen::supervision._detail',
    ])
@endsection
