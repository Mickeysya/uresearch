@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Study Candidacy Appeal',
        'decideRoute' => 'candidacy-appeal.decide',
        'detailView' => 'chloe::candidacy.appeal._detail',
        'allowReturn' => true,
    ])
@endsection
