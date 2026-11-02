@extends('core::layouts.app')

@section('title', $label.' '.$reference)

{{--
    A student's application details page ("View details"). Module-neutral:
    every part comes from Services\ApplicationDetails, which asks the
    module's optional contracts (DescribesApplicationProgress,
    DescribesApplicationDetails, SuppliesDecisionDates,
    SuppliesOverdueStatus) and otherwise says something generic.

    Layout: header and progress across the top, then two columns (timeline
    and your details; documents and help), stacked below 1000px. Styles in
    tracking.css (.trk-detail), tokens only.
--}}

@php
    $badge = [
        'pending' => ['Pending', 'clock'],
        'approved' => ['Approved', 'check'],
        'rejected' => ['Rejected', 'cross'],
        'returned' => ['Returned', 'warning'],
    ][$status] ?? [Str::headline($status), 'info'];
    $stateWords = ['completed' => 'done', 'current' => 'current', 'upcoming' => 'next', 'rejected' => 'rejected', 'skipped' => 'not required'];
    $size = fn (?int $bytes) => $bytes === null ? null : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB');
@endphp

@section('content')
<div class="trk trk-detail">
    <nav class="trk-crumbs" aria-label="Breadcrumb">
        <a href="{{ $back }}">
            <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'chevron'])</span>
            Track My Applications
        </a>
    </nav>

    {{-- ---- header: the shared page header, with the decision date (or
         the student's action) on the right ---- --}}
    <x-core::page-header :title="$title" class="trk-detail-header">
        <x-slot:subtitle>
            <span class="trk-module trk-tone-{{ $category['tone'] }}">{{ $label }}</span>
            <span class="trk-ref">{{ $reference }}</span>
            @if (! empty($issued['reference']))
                <span class="trk-ref">· Letter {{ $issued['reference'] }}</span>
            @endif
        </x-slot:subtitle>

        @if ($primary)
            <a href="{{ $primary['url'] }}" class="btn">{{ $primary['label'] }}</a>
        @elseif ($expected)
            <div class="trk-expect {{ $overdue ? 'is-late' : '' }}">
                <span class="trk-expect-label">Expected decision by</span>
                <span class="trk-expect-date">{{ $expected->format('j M Y') }}</span>
                @if ($overdue)
                    <span class="trk-late">Taking longer than usual</span>
                @endif
            </div>
        @endif
    </x-core::page-header>

    <div class="trk-detail-status {{ $needs_student ? 'trk-card-needs' : '' }}">
        <p class="trk-sub">
            <span class="trk-badge trk-badge-{{ $status }}">
                <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => $badge[1]])</span>
                {{ $badge[0] }}@if ($holder && in_array($status, ['pending', 'returned'], true)) · {{ $status === 'returned' ? 'with you' : 'with '.$holder }}@endif
            </span>
            @if ($needs_student)
                <span class="trk-badge trk-badge-needs">Needs you</span>
            @endif
            <span>Submitted {{ $application->submitted_at?->format('j M Y, g:ia') ?? 'not yet' }}</span>
        </p>
        @if ($rejection)
            <p class="trk-detail-reason">
                <b>Reason from {{ $rejection->stage_label }}:</b>
                @if ($rejection->remarks)
                    <q>{{ $rejection->remarks }}</q>
                @else
                    none was given.
                @endif
            </p>
        @endif
    </div>

    {{-- ---- progress ---- --}}
    <section class="trk-panel" aria-labelledby="trk-progress-title">
        <h2 id="trk-progress-title" class="trk-panel-title">Progress</h2>

        <ol class="trk-steps trk-detail-steps">
            @foreach ($steps as $i => $step)
                <li class="trk-step is-{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    <span class="trk-dot" aria-hidden="true">
                        @if ($step['state'] === 'completed')
                            @include('core::dashboard.partials.icon', ['name' => 'check'])
                        @elseif ($step['state'] === 'rejected')
                            @include('core::dashboard.partials.icon', ['name' => 'cross'])
                        @endif
                    </span>
                    <span class="trk-step-text">
                        <span class="trk-sr">Step {{ $i + 1 }} of {{ count($steps) }}, {{ $stateWords[$step['state']] ?? $step['state'] }}:</span>
                        <span class="trk-step-label">{{ $step['label'] }}</span>
                        @if ($step['who'])
                            <span class="trk-step-who">{{ $step['who'] }}</span>
                        @endif
                        <span class="trk-step-detail">{{ $step['when'] }}</span>
                        @if ($step['timing'])
                            <span class="trk-step-timing {{ $step['late'] ? 'is-late' : '' }}">
                                {{ $step['timing'] }}@if ($step['late'])<span class="trk-sr"> (over target)</span>@endif
                            </span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>

        <div class="trk-next">
            <h3 class="trk-next-title">What happens next</h3>
            <p>{{ $next }}</p>
        </div>
    </section>

    <div class="trk-detail-grid">
        <div class="trk-detail-main">
            {{-- ---- timeline ---- --}}
            <section class="trk-panel" aria-labelledby="trk-activity-title">
                <h2 id="trk-activity-title" class="trk-panel-title">Activity</h2>
                <ol class="trk-timeline">
                    @foreach ($timeline as $event)
                        <li class="trk-event tone-{{ $event['tone'] }}">
                            <span class="trk-event-dot" aria-hidden="true"></span>
                            <div class="trk-event-body">
                                <p class="trk-event-title">{{ $event['title'] }}</p>
                                <p class="trk-event-date"><time datetime="{{ $event['date']->toIso8601String() }}">{{ $event['date']->format('j M Y, g:ia') }}</time></p>
                                @if ($event['detail'])
                                    <p class="trk-event-detail">{{ $event['detail'] }}</p>
                                @endif
                                @if ($event['quote'])
                                    <blockquote class="trk-event-quote">{{ $event['quote'] }}</blockquote>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- ---- your application (modules with details() only) ---- --}}
            @if ($details)
                <section class="trk-panel" aria-labelledby="trk-yours-title">
                    <h2 id="trk-yours-title" class="trk-panel-title">Your application</h2>
                    <dl class="trk-dl">
                        @foreach ($details as $term => $value)
                            <div>
                                <dt>{{ $term }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        </div>

        <div class="trk-detail-side">
            {{-- ---- documents ---- --}}
            <section class="trk-panel" aria-labelledby="trk-docs-title">
                <h2 id="trk-docs-title" class="trk-panel-title">Documents</h2>

                @if ($issued)
                    <div class="trk-letter {{ ! empty($issued['revoked_at']) ? 'is-revoked' : '' }}">
                        <p class="trk-letter-title">{{ $issued['label'] }}</p>
                        @if (! empty($issued['document']))
                            <p class="trk-letter-ref">Reference {{ $issued['reference'] }}</p>
                            @if (! empty($issued['revoked_at']))
                                <p class="trk-letter-revoked">
                                    <span class="trk-badge trk-badge-rejected">Revoked</span>
                                    {{ $issued['revoked_at']->format('j M Y') }}@if (! empty($issued['revoked_reason'])): <q>{{ $issued['revoked_reason'] }}</q>@endif
                                </p>
                            @endif
                            <div class="trk-actions">
                                <a href="{{ $issued['document']->url() }}" class="{{ empty($issued['revoked_at']) ? 'btn' : 'btn-secondary' }}">Download letter (PDF)</a>
                                @if (! empty($issued['verify_url']) && empty($issued['revoked_at']))
                                    <a href="{{ $issued['verify_url'] }}" class="btn-secondary">Verification page</a>
                                @endif
                            </div>
                        @else
                            <p class="trk-muted">Your letter will appear here once it is issued.</p>
                        @endif
                    </div>
                @endif

                @if ($uploads->isNotEmpty())
                    <ul class="trk-files" aria-label="Your uploads">
                        @foreach ($uploads as $doc)
                            <li>
                                <span class="trk-file-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'doc'])</span>
                                <span class="trk-file-text">
                                    <a href="{{ $doc->url() }}">{{ $doc->original_name }}</a>
                                    <span class="trk-muted">{{ $doc->doc_type }}@if ($size($doc->size_bytes)) · {{ $size($doc->size_bytes) }}@endif</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @elseif (! $issued)
                    <p class="trk-muted">No documents on this application.</p>
                @endif
            </section>

            {{-- ---- help ---- --}}
            <section class="trk-panel" aria-labelledby="trk-help-title">
                <h2 id="trk-help-title" class="trk-panel-title">Need help?</h2>
                <p class="trk-help-text">Contact {{ $help['name'] ?? 'CGS' }} and quote <b>{{ $reference }}</b>.</p>
                <ul class="trk-help">
                    @if (! empty($help['email']))
                        <li><a href="mailto:{{ $help['email'] }}?subject={{ rawurlencode($reference.' – '.$label) }}">{{ $help['email'] }}</a></li>
                    @endif
                    @if (! empty($help['phone']))
                        <li>{{ $help['phone'] }}</li>
                    @endif
                    @if (! empty($help['hours']))
                        <li class="trk-muted">{{ $help['hours'] }}</li>
                    @endif
                </ul>
            </section>
        </div>
    </div>
</div>
@endsection
