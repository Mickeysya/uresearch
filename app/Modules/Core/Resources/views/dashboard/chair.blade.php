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
        @include('core::dashboard.partials.approver-alerts')

        {{-- Three then two, which is the split the CGS screen already uses
             and what lets the whole thing sit on one screen. auto-fit works
             it out from the child count; neither row declares a column
             number of its own. --}}
        <div class="approver-row">
            @include('core::dashboard.partials.approver-ageing')
            @include('core::dashboard.partials.approver-queues')
            @include('core::dashboard.partials.approver-oldest')
        </div>

        <div class="approver-row approver-row-aside">
            @include('core::dashboard.partials.chair-nominations')
            @include('core::dashboard.partials.approver-shortcuts')
        </div>
    </div>
@endsection
