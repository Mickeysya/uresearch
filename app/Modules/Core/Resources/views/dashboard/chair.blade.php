@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        Chair of Department.

        The generic approver dashboard renders one stat card per queue, and a
        Chair owns five stages -- six cards, five of them normally zero, over
        a bar chart of five categories with one bar in it. This is the same
        information arranged around the question a Chair actually has: is
        anything sitting on my desk too long, and is anything stopping me
        clearing it.

        Four fixed figures, then blocking alerts, then two panel rows. Every
        panel is its own partial, so changing one never touches another.
    --}}
    <div class="sdash sdash-chair">
        <x-core::welcome-banner
            art="desk"
            subtitle="What is waiting on your decision, and how long it has waited." />

        @include('core::dashboard.partials.chair-stat-cards')
        @include('core::dashboard.partials.chair-alerts')

        <div class="chair-row">
            @include('core::dashboard.partials.chair-queues')
            @include('core::dashboard.partials.chair-oldest')
        </div>

        <div class="chair-row">
            @include('core::dashboard.partials.chair-nominations')
            @include('core::dashboard.partials.chair-shortcuts')
        </div>
    </div>
@endsection
