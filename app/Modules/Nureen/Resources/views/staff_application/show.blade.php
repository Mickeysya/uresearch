@extends('core::layouts.app')

@section('title', $label.' '.$reference)

{{--
    Read-only staff view of one application -- StaffApplicationController.
    Same data as the student's details page (Core\Services\ApplicationDetails)
    and the same tracking.css styles, without the student's own prompts.
    A document is a link only when Core's document rule lets this viewer
    open it.
--}}

@php
    $badge = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Returned'][$status] ?? Str::headline($status);
    $stateWords = ['completed' => 'done', 'current' => 'current', 'upcoming' => 'next', 'rejected' => 'rejected', 'skipped' => 'not required'];
@endphp

@section('content')
<div class="trk trk-detail">
    <x-core::page-header :title="$title" class="trk-detail-header">
        <x-slot:subtitle>
            <span class="trk-module trk-tone-{{ $category['tone'] }}">{{ $label }}</span>
            <span class="trk-ref">{{ $reference }}</span>
            @if (! empty($issued['reference']))
                <span class="trk-ref">· Letter {{ $issued['reference'] }}</span>
            @endif
        </x-slot:subtitle>
    </x-core::page-header>

    <div class="trk-detail-status">
        <p class="trk-sub">
            <span class="trk-badge trk-badge-{{ $status }}">{{ $badge }}@if ($holder && $status === 'pending') · with {{ $holder }}@endif</span>
            <span>{{ $application->student?->name }}@if ($application->student?->matric_no) ({{ $application->student->matric_no }})@endif · submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}</span>
        </p>
        @if ($rejection)
            <p class="trk-detail-reason"><b>Reason from {{ $rejection->stage_label }}:</b> @if ($rejection->remarks)<q>{{ $rejection->remarks }}</q>@else none was given.@endif</p>
        @endif
    </div>

    <section class="trk-panel" aria-labelledby="staff-progress-title">
        <h2 id="staff-progress-title" class="trk-panel-title">Progress</h2>
        <ol class="trk-steps trk-detail-steps">
            @foreach ($steps as $i => $step)
                <li class="trk-step is-{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    <span class="trk-dot" aria-hidden="true">
                        @if ($step['state'] === 'completed') @include('core::dashboard.partials.icon', ['name' => 'check'])
                        @elseif ($step['state'] === 'rejected') @include('core::dashboard.partials.icon', ['name' => 'cross']) @endif
                    </span>
                    <span class="trk-step-text">
                        <span class="trk-sr">Step {{ $i + 1 }} of {{ count($steps) }}, {{ $stateWords[$step['state']] ?? $step['state'] }}:</span>
                        <span class="trk-step-label">{{ $step['label'] }}</span>
                        @if ($step['who'])<span class="trk-step-who">{{ $step['who'] }}</span>@endif
                        <span class="trk-step-detail">{{ $step['when'] }}</span>
                        @if ($step['timing'])<span class="trk-step-timing {{ $step['late'] ? 'is-late' : '' }}">{{ $step['timing'] }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    <div class="trk-detail-grid">
        <div class="trk-detail-main">
            <section class="trk-panel" aria-labelledby="staff-activity-title">
                <h2 id="staff-activity-title" class="trk-panel-title">Activity</h2>
                <ol class="trk-timeline">
                    @foreach ($timeline as $event)
                        <li class="trk-event tone-{{ $event['tone'] }}">
                            <span class="trk-event-dot" aria-hidden="true"></span>
                            <div class="trk-event-body">
                                <p class="trk-event-title">{{ $event['title'] }}</p>
                                <p class="trk-event-date">{{ $event['date']->format('j M Y, g:ia') }}</p>
                                @if ($event['detail'])<p class="trk-event-detail">{{ $event['detail'] }}</p>@endif
                                @if ($event['quote'])<blockquote class="trk-event-quote">{{ $event['quote'] }}</blockquote>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <div class="trk-detail-side">
            @if ($details)
                <section class="trk-panel" aria-labelledby="staff-details-title">
                    <h2 id="staff-details-title" class="trk-panel-title">The application</h2>
                    <dl class="trk-dl">
                        @foreach ($details as $term => $value)
                            <div><dt>{{ $term }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </section>
            @endif

            <section class="trk-panel" aria-labelledby="staff-docs-title">
                <h2 id="staff-docs-title" class="trk-panel-title">Documents</h2>
                @if (! empty($issued['document']))
                    <p class="trk-letter-title">{{ $issued['label'] }} · {{ $issued['reference'] }}@if (! empty($issued['revoked_at'])) · <b>Revoked</b>@endif</p>
                @endif
                <ul class="trk-files">
                    @forelse ($application->documents as $doc)
                        <li>
                            <span class="trk-file-text">
                                @if ($doc->visibleTo($viewer))
                                    <x-core::doc-link :document="$doc">{{ $doc->original_name }}</x-core::doc-link>
                                    · <a href="{{ $doc->url() }}">Download</a>
                                @else
                                    <span>{{ $doc->original_name }}</span>
                                @endif
                                <span class="trk-muted">{{ $doc->doc_type }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="trk-muted">No documents on this application.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
@endsection
