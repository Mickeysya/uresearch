@extends('core::layouts.app')

@section('title', 'GA Extension: ' . $stage->queueTitle())

@section('content')
    @php($bottleneck = $bottleneck ?? collect())

    @include('core::partials.queue', [
        'moduleLabel' => 'GA Extension',
        'decideRoute' => 'ga-extension.decide',
        'detailView' => 'nureen::ga_extension._detail',
        'intro' => view('nureen::ga_extension._queue_intro', ['bottleneck' => $bottleneck])->render(),
    ] + ($canReturn ? [
        // Only CGS Staff, at cgs_verify, gets this -- see
        // GaExtensionController::queue()/returnToSupervisor().
        'returnRoute' => 'ga-extension.return',
        'returnLabel' => 'Send back to the supervisor',
    ] : []))
@endsection
