@extends('core::layouts.app')

@section('title', 'Softbound Thesis: ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Softbound Thesis',
        'decideRoute' => 'viva.softbound.decide',
        'detailView' => 'hani::viva._softbound_detail',
        'intro' => 'Approve verifies the thesis against Form #019. Reject returns it to the student to fix and resubmit, so say what to change in Remarks. Their viva stays open either way.',
    ])
@endsection
