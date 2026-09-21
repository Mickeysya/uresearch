@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        Every approving role without a screen of its own: the Dean of PGR,
        the Academic Executive, the Registry, the Faculty office, the Senior
        Executive.

        This was the last screen still on the pre-.sdash markup, and it was
        the one that showed why the Chair and Supervisor needed their own: it
        built ONE STAT CARD PER QUEUE, so the Academic Executive got seven
        cards of which six normally read zero, over a bar chart of six
        categories with one bar in it -- and never showed how long anything
        had been waiting.

        Now the same five figures and the same panels every approver screen
        uses. The only panel here that the Chair and Supervisor do not have
        is the decision trail, which fits because this screen has no bespoke
        panel taking its place.
    --}}
    <div class="sdash sdash-approver">
        <x-core::welcome-banner
            art="desk"
            subtitle="What is waiting on your decision, and how long it has waited." />

        @include('core::dashboard.partials.approver-stat-cards')
        @include('core::dashboard.partials.approver-alerts')

        <div class="approver-row">
            @include('core::dashboard.partials.approver-ageing')
            @include('core::dashboard.partials.approver-queues')
            @include('core::dashboard.partials.approver-oldest')
        </div>

        <div class="approver-row approver-row-aside">
            @include('core::dashboard.partials.approver-decisions')
            @include('core::dashboard.partials.approver-shortcuts')
        </div>
    </div>
@endsection
