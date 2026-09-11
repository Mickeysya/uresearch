@extends('core::layouts.app')

@section('title', 'Appointment Letter — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Appointment Letter',
        'decideRoute' => 'appointment-letter.decide',
        'detailView' => 'jason::appointment_letter._detail',
    ])
@endsection
