@extends('core::layouts.app')

@section('title', 'TA Hours Approvals')

@push('head')
    @include('norhanis::ta_hours._styles')
@endpush

@php($num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'))

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="TA Hours Approvals" />

    <div class="card card-wide">
        @if (session('status'))
            <p class="message-success">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="message-error">{{ $errors->first() }}</p>
        @endif

        <p class="queue-meta">TA hours students have submitted naming you as their course lecturer. Tick the rows and
            approve or reject them. The student is emailed either way, and a rejection needs a reason.</p>

        @forelse ($groups as $group)
            <form method="POST" action="{{ route('ta-hours.decide') }}" class="ta-add-row" style="margin-top: 18px;">
                @csrf
                <h3 style="margin: 0 0 6px;">{{ $group['student']->name }}@if ($group['student']->matric_no) ({{ $group['student']->matric_no }})@endif</h3>
                <p class="queue-meta" style="margin-top: 0;">{{ $group['month'] }} · {{ $group['rows']->count() }} {{ $group['rows']->count() === 1 ? 'row' : 'rows' }} · {{ $num($group['hours']) }} hrs</p>

                <div style="overflow-x: auto;">
                    <table class="ta-table">
                        <thead>
                            <tr><th></th><th>Date</th><th>Time In</th><th>Time Out</th><th>Hour(s)</th><th>Activity</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as $log)
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="{{ $log->id }}" checked
                                               aria-label="Select {{ $log->activity_date->format('j M Y') }} {{ $log->time_in }}"></td>
                                    <td>{{ $log->activity_date->format('j M Y') }}</td>
                                    <td>{{ $log->time_in }}</td>
                                    <td>{{ $log->time_out }}</td>
                                    <td>{{ $num($log->hours) }}</td>
                                    <td>{{ $log->activityLabel() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <label for="reason-{{ $loop->index }}">Reason (required to reject)</label>
                <input type="text" name="reason" id="reason-{{ $loop->index }}" maxlength="1000">

                <div class="ta-actions">
                    <button type="submit" name="decision" value="approve">Approve selected</button>
                    <button type="submit" name="decision" value="reject" class="btn-reject">Reject selected</button>
                </div>
            </form>
        @empty
            <div class="empty-state">No TA hours are waiting for your approval.</div>
        @endforelse

        @if ($decided->isNotEmpty())
            <h3 style="margin-top: 28px;">Recently decided</h3>
            <div style="overflow-x: auto;">
                <table class="ta-table">
                    <thead>
                        <tr><th>Student</th><th>Date</th><th>Time</th><th>Hour(s)</th><th>Activity</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($decided as $log)
                            <tr>
                                <td>{{ $log->student->name }}</td>
                                <td>{{ $log->activity_date->format('j M Y') }}</td>
                                <td>{{ $log->time_in }}–{{ $log->time_out }}</td>
                                <td>{{ $num($log->hours) }}</td>
                                <td>{{ $log->activityLabel() }}</td>
                                <td>
                                    <span class="status-badge {{ $log->state }}">{{ $log->stateLabel() }}</span>
                                    @if ($log->decision_reason)<div class="ta-reason">{{ $log->decision_reason }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
