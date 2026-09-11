@extends('core::layouts.app')

@section('title', 'Attendance Appeals — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Attendance Appeal',
        'decideRoute' => 'attendance-appeal.decide',
        'detailView' => 'nureen::attendance_appeal._detail',
    ])
@endsection
