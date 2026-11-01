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
                    <div class="stat-label">Latest period</div>
                </div>
                {{-- The figure the 80% requirement and the early-warning
                     email use. Current only: the projection and the trend
                     are CGS's alone and never appear on a student page. --}}
                @if ($semesterSoFar !== null)
                    <div class="stat-card {{ $semesterSoFar < 80 ? 'accent-red' : 'accent-green' }}">
                        <div class="stat-number">{{ rtrim(rtrim(number_format($semesterSoFar, 1), '0'), '.') }}%</div>
                        <div class="stat-label">This semester so far</div>
                    </div>
                @endif
            </div>

            <p style="color: var(--text-grey); font-size: 12.5px;">
                Latest period ending {{ $latest->period_end->format('j M Y') }}.
                The 80% requirement applies to your attendance across the semester.
                Sessions excused by an approved appeal {{ \App\Modules\Nureen\Models\AttendanceRecord::excusalRule() === 'exclude_from_total' ? 'are left out of the total' : 'count as attended' }}.
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
