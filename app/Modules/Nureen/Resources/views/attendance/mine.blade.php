@extends('core::layouts.app')

@section('title', 'My Attendance')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>My Attendance</h2>
        <div class="card-divider"></div>

        @php($latest = $records->first())

        @if ($latest)
            <div class="stat-cards-row">
                <div class="stat-card {{ $latest->at_risk ? 'accent-red' : 'accent-green' }}">
                    <div class="stat-number">{{ $latest->percentage }}%</div>
                    <div class="stat-label">Current attendance</div>
                </div>
            </div>

            @if ($latest->at_risk)
                <div class="empty-state" style="border-color: var(--red, #c0392b);">
                    You are currently flagged at-risk (mandatory threshold is 80%).
                    <a href="{{ route('attendance-appeal.create') }}">File an appeal</a> if you believe this is inaccurate.
                </div>
            @endif
        @endif

        <h3 style="color: var(--navy); font-size: 15px; margin-top: 24px;">History</h3>

        @forelse ($records as $record)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Period ending {{ $record->period_end->format('j M Y') }}</b></p>
                    <span style="color: var(--text-grey); font-size: 12.5px;">
                        {{ $record->sessions_attended }} / {{ $record->sessions_total }} sessions
                    </span>
                </div>
                <p>{{ $record->percentage }}%
                    @if ($record->at_risk) <span style="color: var(--red, #c0392b);">— at risk</span> @endif
                </p>
            </div>
        @empty
            <div class="empty-state">No attendance data uploaded yet.</div>
        @endforelse
    </div>
</div>
@endsection
