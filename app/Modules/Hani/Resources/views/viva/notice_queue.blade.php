@extends('core::layouts.app')

@section('title', 'Viva Voce: ' . $stage->queueTitle())

{{--
    The Notice of Thesis Submission queue, laid out as UCampus lays it out
    (UAT 11 Nov 2026): Student Details, Request Summary, Documents,
    Eligibility, then the Academic Executive's Verification. Its own page
    rather than core::partials.queue, because the decision is Verified /
    Rejected with a per-item eligibility check, not Approve / Reject.
    One at a time (VivaVoceWorkflow is DecidesOneAtATime).
--}}
@section('content')
    @php
        $total = $applications->total();
    @endphp

    <x-core::page-header :title="'Viva Voce: '.$stage->queueTitle()"
        :subtitle="$total === 0 ? 'Nothing is waiting on you right now.' : number_format($total).' '.Str::plural('notice', $total).' awaiting your '.($endorsing ? 'endorsement' : 'verification').'.'" />

    @if ($total > 0 || $filters['q'] !== '')
        <form method="GET" class="queue-tools">
            <input type="hidden" name="stage" value="{{ $stage->key }}">
            <div class="queue-tool-field">
                <label for="queue-q">Find a notice</label>
                <input type="search" name="q" id="queue-q" value="{{ $filters['q'] }}" placeholder="Application number, student name or matric number">
            </div>
            <button type="submit" class="btn-secondary">Search</button>
        </form>
    @endif

    @if ($applications->isEmpty())
        <div class="empty-state"><p>Nothing is waiting on you right now.</p></div>
    @else
        <div class="queue-list">
            @foreach ($applications as $application)
                @include('hani::viva._notice_card', ['application' => $application, 'detail' => $details[$application->id] ?? null, 'record' => $eligibility->get($application->student_id), 'verifying' => ! $endorsing, 'endorsing' => $endorsing])
            @endforeach
        </div>
        @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Queue pages'])
    @endif
@endsection
