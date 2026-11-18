@extends('core::layouts.app')

@section('title', 'GA Extension: ' . $stage->queueTitle())

@section('content')
    @php($bottleneck = $bottleneck ?? collect())

    @include('core::partials.queue', [
        'moduleLabel' => 'GA Extension',
        'decideRoute' => 'ga-extension.decide',
        'detailView' => 'nureen::ga_extension._detail',
        // Returns are offered by each row's own decision bar (ApproverPanel::
        // gaExtension(), one button per GaExtensionWorkflow::returnTargets()
        // entry), not by Core's single-route return form.
        'intro' => view('nureen::ga_extension._queue_intro', ['bottleneck' => $bottleneck])->render(),
    ])
@endsection
