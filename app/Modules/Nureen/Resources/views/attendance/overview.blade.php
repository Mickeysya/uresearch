@extends('core::layouts.app')

@section('title', 'Attendance Overview')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Attendance Overview" />

    <div class="card card-wide">
        @if ($latest)
            <div class="stat-cards-row">
                <div class="stat-card {{ $latest->at_risk ? 'accent-red' : 'accent-green' }}">
                    <div class="stat-number">{{ $latest->percentage }}%</div>
                    <div class="stat-label">Current attendance</div>
                </div>
            </div>

            <p style="color: var(--text-grey); font-size: 12.5px;">
                As of period ending {{ $latest->period_end->format('j M Y') }}.
                Mandatory threshold is 80%.
            </p>

            @if ($latest->at_risk)
                <div class="empty-state" style="border-color: var(--red, #c0392b);">
                    <p>You are currently flagged at-risk.</p>
                    <p class="queue-meta">File an appeal if you believe this is inaccurate.</p>
                    <a href="{{ route('attendance-appeal.create') }}" class="btn-secondary">File an appeal</a>
                </div>
            @endif
        @else
            <div class="empty-state">No attendance data uploaded yet.</div>
        @endif
    </div>
</div>
@endsection
