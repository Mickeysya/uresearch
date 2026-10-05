@extends('core::layouts.app')

@section('title', 'GA/GRA Certification: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'GA/GRA Certification Letter',
        'decideRoute' => 'ga-certification.decide',
        'bulk' => false, // decide() does more than the engine; Core's bulk path would skip it
        'detailView' => 'nureen::ga_certification._detail',
    ])
@endsection
