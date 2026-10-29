@extends('core::layouts.app')

@section('title', 'Viva Voce: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Viva Voce',
        'decideRoute' => 'viva.decide',
        'detailView' => 'hani::viva._detail',
    ])
@endsection
