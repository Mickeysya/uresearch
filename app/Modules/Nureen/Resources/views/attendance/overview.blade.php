@extends('core::layouts.app')

@section('title', 'Attendance Overview')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Attendance Overview</h2>
        <div class="card-divider"></div>

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
                    You are currently flagged at-risk.
                    <a href="{{ route('attendance-appeal.create') }}">File an appeal</a> if you believe this is inaccurate.
                </div>
            @endif
        @else
            <div class="empty-state">No attendance data uploaded yet.</div>
        @endif
    </div>
</div>
@endsection
