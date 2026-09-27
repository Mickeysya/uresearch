@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal: ' . $stage->queueTitle())

{{--
    Not @include('core::partials.queue', ...): that partial's decision form
    does not pass allowReturn, and this chain needs the Return button (back
    to the student for revision). Everything else is the partial's own
    markup and classes, one collapsible row per appeal, so the screen reads
    the same as every other queue. What it leaves out is search and bulk
    decisions; bulk could not offer Return anyway. See
    app/Modules/Chloe/README.md.
--}}
@section('content')
@php
    $total = $applications->total();
    $overdueAfter = 14;
    $criticalAfter = 30;
@endphp

<x-core::page-header :title="'Study Candidacy Appeal: '.$stage->queueTitle()"
                     :subtitle="$total === 0 ? 'Nothing is waiting on you right now.' : number_format($total).' '.Str::plural('appeal', $total).' awaiting your decision.'" />

<p class="queue-intro">
    <b>Return</b> sends an appeal back to the student to revise; it comes back to you when they resubmit.
    <b>Reject</b> is final and blocks any further appeal for that candidacy.
</p>

@if ($applications->isEmpty())
    <div class="empty-state">
        <p>Nothing is waiting on you right now.</p>
        <p class="queue-meta">Appeals appear here the moment they reach your stage.</p>
    </div>
@else
    <div class="queue-list">
        @foreach ($applications as $application)
            @php
                $detail = $details[$application->id] ?? null;
                $waiting = (int) ($application->submitted_at?->diffInDays() ?? 0);
                $tone = match (true) {
                    $waiting >= $criticalAfter => 'critical',
                    $waiting >= $overdueAfter => 'warn',
                    default => 'quiet',
                };
            @endphp

            <details class="queue-row">
                <summary class="queue-row-summary">
                    <span class="queue-row-id">#{{ $application->id }}</span>

                    <span class="queue-row-who">
                        {{ $application->student->name }}
                        @if ($application->student->matric_no)
                            <span class="queue-row-matric">{{ $application->student->matric_no }}</span>
                        @endif
                    </span>

                    <span class="queue-row-summary-text">
                        @if ($detail)
                            {{ $detail->requested_extension_months }} {{ Str::plural('month', $detail->requested_extension_months) }} requested
                        @else
                            Study candidacy appeal
                        @endif
                    </span>

                    @if ($application->documents->isNotEmpty())
                        <span class="queue-row-docs" title="{{ $application->documents->count() }} attached">
                            {{ $application->documents->count() }} file{{ $application->documents->count() === 1 ? '' : 's' }}
                        </span>
                    @endif

                    <span class="queue-row-age tone-{{ $tone }}">
                        {{ $waiting === 0 ? 'today' : $waiting.'d' }}
                    </span>

                    <span class="queue-row-chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                </summary>

                <div class="queue-row-body">
                    <p class="queue-row-submitted">
                        Submitted {{ $application->submitted_at?->diffForHumans() }}
                        @if ($application->submitted_at)
                            on {{ $application->submitted_at->format('j M Y') }}
                        @endif
                    </p>

                    @if ($detail)
                        @include('chloe::candidacy.appeal._sections', ['detail' => $detail])
                    @else
                        <p class="queue-meta">Detail record missing for this appeal.</p>
                    @endif

                    @if ($application->documents->isNotEmpty())
                        <ul class="doc-list">
                            @foreach ($application->documents as $doc)
                                <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                            @endforeach
                        </ul>
                    @endif

                    <x-core::decision-form :application="$application"
                                           :route="route('candidacy-appeal.decide', $application)"
                                           :allow-return="true" />
                </div>
            </details>
        @endforeach
    </div>

    @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Queue pages'])
@endif
@endsection
