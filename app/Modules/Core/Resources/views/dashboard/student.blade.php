@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        Single-screen layout: the page itself never scrolls, individual panels
        do. .sdash is a three-row grid sized to the viewport (banner, stat
        cards, then the panels taking whatever height is left), and every
        panel body is its own scroll container.

        Each panel is its own partial, so changing one never touches another.
    --}}
    <div class="sdash">
        <x-core::welcome-banner />

        @include('core::dashboard.partials.stat-cards')

        <div class="sdash-panels">
            <div class="sdash-col sdash-col-left">
                @include('core::dashboard.partials.application-status')
                @include('core::dashboard.partials.notifications')
            </div>

            <div class="sdash-col sdash-col-right">
                @include('core::dashboard.partials.attendance-overview')
                @include('core::dashboard.partials.upcoming-tasks')
            </div>
        </div>
    </div>
@endsection
