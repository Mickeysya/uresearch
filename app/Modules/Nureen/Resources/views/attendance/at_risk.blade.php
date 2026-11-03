@extends('core::layouts.app')

@section('title', 'At-Risk Students')

{{--
    CGS's predictive attendance view -- the ONLY place the end-of-semester
    projection and the trend appear. Students never see either: their own
    pages show their current attendance and history only. Figures come from
    attendance_risk_states, written by Support\AttendanceEarlyWarning after
    every sync and upload.
--}}

@section('content')
@php
    $reasonLabels = [
        'current' => ['Current', 'var(--danger-bg)', 'var(--danger-fg)', 'Below, or close to, the requirement now (the original rule)'],
        'projected' => ['Projected', 'var(--warning-bg)', 'var(--warning-fg)', 'Compliant now, but the trend projects below the requirement by the end of the semester'],
        'both' => ['Both', 'var(--danger-bg)', 'var(--danger-fg)', 'Flagged by the original rule and by the projection'],
    ];
    $sortLink = fn (string $key) => route('attendance.at-risk', array_filter(['sort' => $key, 'q' => $search]));
    $pct = fn (?float $value) => $value === null ? null : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
@endphp

<div class="card-container-inline">
    <x-core::page-header
        title="At-Risk Students"
        subtitle="{{ $states->count() }} {{ Str::plural('student', $states->count()) }} below the {{ $pct($threshold) }} attendance requirement, close to it, or projected to fall below it by the end of the semester." />

    {{-- Core's CGS sidebar lists its attendance links by hand
         (SHARED_CHANGES.md §16), so Approved Leave is linked from here. --}}
    <p style="margin: 0 0 var(--space-4);">
        <a href="{{ route('attendance.leave') }}" class="btn-secondary">Approved Leave</a>
    </p>

    {{-- Approved leave over the allowance this year (Support\AttendanceLeave). --}}
    @if ($leaveOver->isNotEmpty())
        <div class="message-warning" role="status" style="margin-bottom: var(--space-4);">
            <p style="margin: 0 0 var(--space-1);">
                <b>{{ $leaveOver->count() }} {{ Str::plural('student', $leaveOver->count()) }} over the approved-leave allowance this year.</b>
                The leave still counts; check it with the Chair. <a href="{{ route('attendance.leave') }}">See Approved Leave</a>
            </p>
            <ul style="margin: 0; padding-left: var(--space-5);">
                @foreach ($leaveOver as $row)
                    @foreach ($row['problems'] as $problem)
                        <li>{{ $row['student']->name }} ({{ $row['student']->matric_no }}): {{ $problem }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" class="queue-tools">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <div class="queue-tool-field">
            <label for="at-risk-q">Find a student</label>
            <input type="search" name="q" id="at-risk-q" value="{{ $search }}" placeholder="Matric number or name">
        </div>
        <button type="submit" class="btn-secondary">Search</button>
        @if ($search !== '')
            <a href="{{ route('attendance.at-risk', ['sort' => $sort]) }}" class="queue-clear">Clear</a>
        @endif
    </form>

    <div class="card card-wide">
        @if ($states->isEmpty())
            <div class="empty-state">
                {{ $search !== '' ? 'No at-risk student matches "'.$search.'".' : 'No students currently at risk.' }}
            </div>
        @else
            <p class="queue-meta" style="margin-top: 0;">
                <b>Current</b> is attendance so far this semester (sessions excused by approved appeals {{ \App\Modules\Nureen\Models\AttendanceRecord::excusalRule() === 'exclude_from_total' ? 'are left out of the total' : 'count as attended' }}).
                <b>Projected</b> extends each student's own trend, a least-squares line through their per-period
                attendance, over the rest of a {{ $semesterPeriods }}-period semester. <b>Trend</b> is in percentage points per period.
                Students are emailed automatically; they are never shown the projection.
            </p>

            <div class="table-scroll">
                <table class="spec-table" id="at-risk-table">
                    <thead>
                        <tr>
                            {{-- Sortable headers look like the other headers; the
                                 arrow marks the active sort (lowest first). --}}
                            <th><a href="{{ $sortLink('name') }}" style="color: inherit; text-decoration: none;" title="Sort by name">Student @if ($sort === 'name') ▲ @else ⇅ @endif</a></th>
                            <th><a href="{{ $sortLink('current') }}" style="color: inherit; text-decoration: none;" title="Sort by current attendance, lowest first">Current @if ($sort === 'current') ▲ @else ⇅ @endif</a></th>
                            <th><a href="{{ $sortLink('projected') }}" style="color: inherit; text-decoration: none;" title="Sort by projected end-of-semester attendance, lowest first">Projected end of semester @if ($sort === 'projected') ▲ @else ⇅ @endif</a></th>
                            <th>Trend</th>
                            <th>Reason</th>
                            <th>Last emailed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($states as $state)
                            @php($reason = $reasonLabels[$state->reason] ?? null)
                            <tr data-matric="{{ $state->student?->matric_no }}">
                                <td>
                                    <b>{{ $state->student?->name }}</b>
                                    @if ($state->student?->matric_no)
                                        <span class="queue-meta">{{ $state->student->matric_no }}</span>
                                    @endif
                                    <div class="queue-meta" style="margin-top: 3px; line-height: 1.35;">{{ $state->student?->programme }}</div>
                                </td>
                                <td style="font-weight: var(--weight-semi); color: {{ ($state->current_percentage ?? 100) < $threshold ? 'var(--danger-fg)' : 'var(--text-dark)' }};">
                                    {{ $pct($state->current_percentage) ?? 'No data' }}
                                </td>
                                <td>
                                    @if ($state->projected_percentage !== null)
                                        <span style="font-weight: var(--weight-semi); color: {{ $state->projected_percentage < $threshold ? 'var(--danger-fg)' : 'var(--text-dark)' }};">{{ $pct($state->projected_percentage) }}</span>
                                    @elseif ($state->projection_status === 'inconsistent_periods')
                                        <span class="queue-meta" title="This student's periods this semester are not all the same length (e.g. monthly and weekly), so no trend line can be fitted. The original rule still applies.">Projection unavailable: inconsistent periods</span>
                                    @else
                                        <span class="queue-meta">Not enough periods yet</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($state->trend_slope !== null)
                                        <span style="white-space: nowrap; color: {{ $state->trend_slope < 0 ? 'var(--danger-fg)' : ($state->trend_slope > 0 ? 'var(--success-fg)' : 'var(--text-grey)') }};">
                                            {{ $state->trend_slope < 0 ? '▼' : ($state->trend_slope > 0 ? '▲' : '▶') }}
                                            {{ ($state->trend_slope > 0 ? '+' : '').number_format($state->trend_slope, 1) }} pts/period
                                        </span>
                                    @elseif ($state->projection_status === 'inconsistent_periods')
                                        <span class="queue-meta">Unavailable</span>
                                    @else
                                        <span class="queue-meta">Not enough periods yet</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($reason)
                                        <span title="{{ $reason[3] }}" style="display: inline-block; padding: 2px 10px; border-radius: var(--radius-full); font-size: var(--text-xs); font-weight: var(--weight-semi); background: {{ $reason[1] }}; color: {{ $reason[2] }};">{{ $reason[0] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($state->last_emailed_at)
                                        <span style="white-space: nowrap;">{{ \Illuminate\Support\Carbon::parse($state->last_emailed_at)->format('j M Y') }}</span>
                                        <span class="queue-meta" style="white-space: nowrap;">{{ \Illuminate\Support\Carbon::parse($state->last_emailed_at)->format('g:i a') }}</span>
                                    @else
                                        <span class="queue-meta">Not yet</span>
                                    @endif
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
