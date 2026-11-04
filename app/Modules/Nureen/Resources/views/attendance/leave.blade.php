@extends('core::layouts.app')

@section('title', 'Approved Leave')

{{--
    Approved annual leave per student against the allowance
    (config('nureen.attendance.leave'); Postgraduate Handbook 2025). Leave
    approved by the Chair via UCampus never counts as absence: it is folded
    in exactly like sessions an appeal excuses. Recorded per attendance
    period -- here, or by the sheet's optional leave_days column.
--}}

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Approved Leave"
        subtitle="Full-time research students get up to {{ $config['annual_days'] }} days a year ({{ collect($config['semesters'])->map(fn ($s) => $s['label'].' '.$s['days'])->implode(', ') }}). Unused leave is not carried over." />

    @include('nureen::attendance._leave_warnings')

    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3 style="margin-top: 0;">Record approved leave</h3>
        <p class="queue-meta" style="margin-top: 0;">For a period already on file. The sheet's optional <code>leave_days</code> column does the same for many students at once.</p>
        <form method="POST" action="{{ route('attendance.leave.store') }}" class="leave-form">
            @csrf
            <div>
                <label for="leave-matric">Matric number</label>
                <input type="text" name="matric_no" id="leave-matric" required maxlength="30" value="{{ old('matric_no') }}" class="@error('matric_no') is-invalid @enderror">
                @error('matric_no') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="leave-period">Period ending</label>
                <input type="date" name="period_end" id="leave-period" required value="{{ old('period_end') }}" class="@error('period_end') is-invalid @enderror">
                @error('period_end') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="leave-days">Leave days in that period</label>
                <input type="number" name="leave_days" id="leave-days" required min="0" max="31" value="{{ old('leave_days') }}" class="@error('leave_days') is-invalid @enderror">
                @error('leave_days') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="leave-form-submit"><button type="submit">Record leave</button></div>
        </form>
    </div>

    <div class="card card-wide">
        <div class="leave-head">
            <h3 style="margin: 0;">Leave recorded in {{ $year }}</h3>
            <span class="queue-meta">
                <a href="{{ route('attendance.leave', ['year' => $year - 1]) }}">{{ $year - 1 }}</a>
                · <a href="{{ route('attendance.leave', ['year' => $year + 1]) }}">{{ $year + 1 }}</a>
            </span>
        </div>

        @if ($rows->isEmpty())
            <p class="queue-meta">No leave recorded for {{ $year }}.</p>
        @else
            <div class="leave-table-wrap">
            <table class="leave-table">
                <thead>
                    <tr>
                        <th scope="col">Student</th>
                        @foreach ($config['semesters'] as $semester)
                            <th scope="col">{{ $semester['label'] }}</th>
                        @endforeach
                        <th scope="col">Year</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @if ($row['problems'] !== []) class="leave-over" @endif>
                            <td>
                                <b>{{ $row['student']->name }}</b> <span class="queue-meta">{{ $row['student']->matric_no }}</span>
                                @foreach ($row['problems'] as $problem)
                                    <span class="leave-problem">⚠ {{ $problem }}</span>
                                @endforeach
                            </td>
                            @foreach ($row['usage']['semesters'] as $semester)
                                <td class="{{ $semester['over'] ? 'leave-cell-over' : '' }}">{{ $semester['days'] }} / {{ $semester['allowance'] }}</td>
                            @endforeach
                            <td class="{{ $row['usage']['over_year'] ? 'leave-cell-over' : '' }}">{{ $row['usage']['total'] }} / {{ $row['usage']['allowance'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>

@push('head')
<style>
    .leave-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0 var(--space-4); align-items: end; }
    .leave-form-submit { padding-bottom: var(--space-1); }
    .leave-head { display: flex; justify-content: space-between; align-items: baseline; gap: var(--space-3); flex-wrap: wrap; margin-bottom: var(--space-3); }
    .leave-table-wrap { overflow-x: auto; }
    .leave-table { width: 100%; border-collapse: collapse; }
    .leave-table th, .leave-table td { text-align: left; padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--border-subtle); vertical-align: top; }
    .leave-table th { color: var(--text-grey); font-weight: var(--weight-semi); font-size: var(--text-sm); }
    .leave-cell-over { color: var(--danger-fg); font-weight: var(--weight-semi); }
    .leave-problem { display: block; color: var(--warning-fg); font-size: var(--text-sm); margin-top: var(--space-1); }
</style>
@endpush
@endsection
