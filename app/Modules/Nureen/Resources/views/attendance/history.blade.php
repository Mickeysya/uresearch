@extends('core::layouts.app')

@section('title', 'Attendance History')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Attendance History</h2>
        <div class="card-divider"></div>

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
