@extends('core::layouts.app')
@section('title', 'Dashboard')
@section('content')
    <div class="sdash">
        <x-core::welcome-banner :subtitle="$bannerSubtitle" />

        @include('core::dashboard.partials.top-cards')

        {{-- One grid, four direct children -- see dashboard-student.css'
             .sdash-panels comment: row-mates share a grid row track, so
             quick-links/attendance-overview size to the taller of the two,
             and application-status/notifications do the same below them. --}}
        <div class="sdash-panels">
            @include('core::dashboard.partials.quick-links')
            @include('core::dashboard.partials.attendance-overview')
            @include('core::dashboard.partials.application-status')
            @include('core::dashboard.partials.notifications')
        </div>
    </div>
@endsection
