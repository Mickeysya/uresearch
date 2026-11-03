@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        Natural-height layout: the whole page scrolls, nothing inside a card
        does. .sdash is a three-row grid (banner, stat cards, panels), each
        row exactly as tall as its content.

        .sdash-panels is a single 2-column grid holding all four cards
        directly -- not two independently-stacked columns -- so a row's two
        cards share one grid row track and size to the taller of the two via
        the grid's default align-items: stretch. Order here is the pairing:
        application-status sits beside attendance-overview, notifications
        beside upcoming-tasks.

        Each panel is its own partial, so changing one never touches another.
    --}}
    <div class="sdash">
        <x-core::welcome-banner />

        @include('core::dashboard.partials.stat-cards')

        <div class="sdash-panels">
            @include('core::dashboard.partials.application-status')
            @include('core::dashboard.partials.attendance-overview')
            @include('core::dashboard.partials.notifications')
            @include('core::dashboard.partials.upcoming-tasks')
        </div>
    </div>
@endsection
