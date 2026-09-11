@extends('core::layouts.app')

@section('title', 'GA/GRA Certification — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'GA/GRA Certification Letter',
        'decideRoute' => 'ga-certification.decide',
        'detailView' => 'nureen::ga_certification._detail',
    ])
@endsection
