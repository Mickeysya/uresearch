@extends('core::layouts.app')
@section('title', 'RPD Appeal / Extension — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'RPD Appeal / Extension',
        'decideRoute' => 'rpd-appeal.decide',
        'detailView' => 'norhanis::rpd_appeal._detail',
    ])
@endsection
