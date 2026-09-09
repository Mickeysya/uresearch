@extends('core::layouts.app')

@section('title', 'GA Extension — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'GA Extension',
        'decideRoute' => 'ga-extension.decide',
        'detailView' => 'nureen::ga_extension._detail',
    ])
@endsection
