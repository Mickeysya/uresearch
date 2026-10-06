@extends('core::layouts.app')

@section('title', 'Candidacy Management')

@section('content')
@include('chloe::partials.styles')

@php
    $summaryTones = ['green', 'orange', 'purple', 'teal'];
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Candidacy Management"
                         subtitle="Study candidacy appeals in progress, and the students whose candidacy is about to run out.">
        <a href="{{ route('candidacy.cgs.dismissals') }}" class="btn-secondary">Dismissal list</a>
    </x-core::page-header>

    <div class="sdash-stats chloe-stats">
        <div class="sdash-stat tone-blue">
            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'clock'])</span>
                <span class="sdash-stat-label">Pending appeals</span>
            </div>
            <div class="sdash-stat-value">{{ $pendingAppeals->count() }}</div>
        </div>
        @foreach ($appealStatusSummary as $label => $count)
            <div class="sdash-stat tone-{{ $summaryTones[$loop->index % count($summaryTones)] }}">
                <div class="sdash-stat-top">
                    <span class="sdash-stat-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'doc'])</span>
                    <span class="sdash-stat-label">{{ $label }}</span>
                </div>
                <div class="sdash-stat-value">{{ $count }}</div>
            </div>
        @endforeach
    </div>

    <div class="chloe-stack">
        <div class="card card-wide">
            <h3>Pending appeals</h3>

            @if ($pendingAppeals->isEmpty())
                <div class="empty-state">No appeals are in progress.</div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Reference</th><th>Student</th><th>Stage</th><th>Submitted</th></tr></thead>
                        <tbody>
                            @foreach ($pendingAppeals as $application)
                                <tr>
                                    <td>{{ $application->reference() }}</td>
                                    <td>{{ $application->student->name ?? '—' }}</td>
                                    <td>{{ $application->currentStage()?->label ?? '—' }}</td>
                                    <td>{{ $application->submitted_at?->format('j M Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card card-wide">
            <h3>Students near candidacy expiry</h3>
            <p class="queue-meta">Active candidacies expiring within three months.</p>

            @if ($nearExpiry->isEmpty())
                <div class="empty-state">No students are near expiry.</div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Expiry date</th><th>Remaining</th></tr></thead>
                        <tbody>
                            @foreach ($nearExpiry as $candidacy)
                                @php($days = $candidacy->daysUntilExpiry())
                                <tr>
                                    <td>{{ $candidacy->student->name ?? '—' }}</td>
                                    <td class="rpd-num">{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</td>
                                    <td class="rpd-num tone-{{ $days < 0 ? 'critical' : ($days <= 30 ? 'warn' : 'info') }}">
                                        {{ $days < 0 ? abs($days).'d overdue' : $days.'d' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
