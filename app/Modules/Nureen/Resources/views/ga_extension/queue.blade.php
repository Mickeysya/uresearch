@extends('core::layouts.app')

@section('title', 'GA Extension: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'GA Extension',
        'decideRoute' => 'ga-extension.decide',
        'detailView' => 'nureen::ga_extension._detail',
    ] + ($canReturn ? [
        // Only CGS Staff, at cgs_verify, gets this -- see
        // GaExtensionController::queue()/returnToSupervisor().
        'returnRoute' => 'ga-extension.return',
        'returnLabel' => 'Send back to the supervisor',
    ] : []))
@endsection
