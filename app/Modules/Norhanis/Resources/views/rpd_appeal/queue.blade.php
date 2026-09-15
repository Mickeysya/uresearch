@extends('core::layouts.app')

@section('title', 'RPD Appeals — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'RPD Extension Appeal',
        'decideRoute' => 'rpd-appeal.decide',
        'detailView' => 'norhanis::rpd_appeal._detail',
    ])
@endsection
