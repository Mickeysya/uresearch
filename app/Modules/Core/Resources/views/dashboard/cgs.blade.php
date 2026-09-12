@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        CGS staff dashboard — a single screen, by requirement.

        Four grid rows: banner, the five figures, then two panel rows. The
        page itself never scrolls on a desktop viewport; panels that outgrow
        their row scroll internally instead. See the .sdash-cgs block in
        public/css/uresearch.css for how the rows are proportioned.

        Every panel is its own partial, so changing one never touches another.
    --}}
    <div class="sdash sdash-cgs">
        <x-core::welcome-banner
            art="desk"
            subtitle="Here's what's happening with postgraduate administration today." />

        @include('core::dashboard.partials.cgs-stat-cards')

        <div class="cgs-row cgs-row-top">
            @include('core::dashboard.partials.cgs-workload')
            @include('core::dashboard.partials.cgs-pending-actions')
            @include('core::dashboard.partials.cgs-attendance-alerts')
        </div>

        <div class="cgs-row cgs-row-bottom">
            @include('core::dashboard.partials.cgs-recent-activities')
            @include('core::dashboard.partials.cgs-shortcuts')
        </div>
    </div>
@endsection
