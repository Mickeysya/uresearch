@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        Supervisor.

        The queues half is the Chair's furniture, shared: same alert strip,
        same queue list, same triage feed, same quick actions. What makes this
        a different screen is the candidates panel -- a supervisor is
        accountable for named students, not just for a desk, and "is any of my
        candidates in trouble" is a question no queue can answer.

        A supervisor owns SEVEN stages, which is why the generic approver
        screen was worse for them than for anyone: seven stat cards, most of
        them reading zero.
    --}}
    <div class="sdash sdash-supervisor">
        <x-core::welcome-banner
            art="desk"
            subtitle="Your candidates, and what is waiting on your decision." />

        @include('core::dashboard.partials.supervisor-stat-cards')
        @include('core::dashboard.partials.approver-alerts')

        {{-- Three then two, as on the Chair's screen and the CGS one, which
             is what lets the whole thing sit on one screen. --}}
        <div class="approver-row">
            @include('core::dashboard.partials.supervisor-candidates')
            {{-- A doughnut, not the Chair's bar chart. Their screen asks how
                 long work has sat; this one asks whether the cohort is
                 healthy, which is parts of a whole. --}}
            @include('core::dashboard.partials.supervisor-attendance')
            @include('core::dashboard.partials.approver-queues')
        </div>

        <div class="approver-row approver-row-aside">
            @include('core::dashboard.partials.approver-oldest')
            @include('core::dashboard.partials.approver-shortcuts')
        </div>
    </div>
@endsection
