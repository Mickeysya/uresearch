@extends('core::layouts.app')

@section('title', "Daily Log TA Hours: {$monthName} {$year}")

@push('head')
    @include('norhanis::ta_hours._styles')
@endpush

@php
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    $drafts = $logs->where('state', \App\Modules\Norhanis\Models\TaHourLog::DRAFT);
    // Reopen the "+" form when the last add failed validation.
    $addOpen = $errors->hasAny(['activity_date', 'time_in', 'time_out', 'activity_type', 'course_lecturer']);
@endphp

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Daily Log TA Hours" />

    <div class="card card-wide">
        <div class="ta-period">
            <div>Year : {{ $year }}</div>
            <div>Month : {{ $monthName }}</div>
        </div>

        @if (session('status'))
            <p class="message-success">{{ session('status') }}</p>
        @endif
        @error('submit') <p class="message-error">{{ $message }}</p> @enderror

        <p class="message-info">Click the <b>+</b> button to add rows. When every row for the month is in, click <b>Submit</b>
            so your course lecturer can approve them. Only approved hours count.</p>

        <p class="queue-meta">Logged this month: <b>{{ $num($counted) }} / {{ $num($cap) }} hrs</b>
            <span style="color: var(--text-grey);">(monthly cap, no carry-forward; rejected rows do not count)</span></p>

        <div style="overflow-x: auto;">
            <table class="ta-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Hour(s)</th>
                        <th>Activity</th>
                        <th>Course Lecturer</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->activity_date->format('j M Y') }}</td>
                            <td>{{ $log->time_in ?? '—' }}</td>
                            <td>{{ $log->time_out ?? '—' }}</td>
                            <td>{{ $num($log->hours) }}</td>
                            <td>{{ $log->activityLabel() }}</td>
                            <td>{{ $log->lecturerDisplay() }}</td>
                            <td>
                                <span class="status-badge {{ $log->state }}">{{ $log->stateLabel() }}</span>
                                @if ($log->state === 'rejected' && $log->decision_reason)
                                    <div class="ta-reason">Reason: {{ $log->decision_reason }}</div>
                                @endif
                            </td>
                            <td>
                                @if (in_array($log->state, ['draft', 'rejected'], true))
                                    <form method="POST" action="{{ route('ta-hours.destroy', $log) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-reject" style="padding: 4px 10px; font-size: 12px;">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty-state">No rows for {{ $monthName }} {{ $year }} yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <button type="button" class="ta-plus" id="ta-add-toggle" aria-controls="ta-add-row"
                aria-expanded="{{ $addOpen ? 'true' : 'false' }}" aria-label="Add a row" style="margin-top: 14px;">+</button>

        <form method="POST" action="{{ route('ta-hours.store', [$year, $month]) }}" id="ta-add-row"
              class="app-form ta-add-row" @unless ($addOpen) hidden @endunless>
            @csrf
            <p class="message-info">Please provide the time in the 24-hour format (HH:MM). For example, 13:45 represents 1:45pm.</p>

            <label for="activity_date">Date</label>
            <input type="date" name="activity_date" id="activity_date" required
                   min="{{ $minDate }}" max="{{ $maxDate }}" value="{{ old('activity_date') }}"
                   class="@error('activity_date') is-invalid @enderror">
            @error('activity_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="time_in">Time In (HH:MM)</label>
            <input type="text" name="time_in" id="time_in" required placeholder="e.g. 08:30"
                   pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" value="{{ old('time_in') }}"
                   class="@error('time_in') is-invalid @enderror">
            @error('time_in') <p class="field-error">{{ $message }}</p> @enderror

            <label for="time_out">Time Out (HH:MM)</label>
            <input type="text" name="time_out" id="time_out" required placeholder="e.g. 10:30"
                   pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" value="{{ old('time_out') }}"
                   class="@error('time_out') is-invalid @enderror">
            @error('time_out') <p class="field-error">{{ $message }}</p> @enderror

            <label for="activity_type">Activity</label>
            <select name="activity_type" id="activity_type" required class="@error('activity_type') is-invalid @enderror">
                <option value="">-- Select --</option>
                @foreach ($activities as $key => $label)
                    <option value="{{ $key }}" @selected(old('activity_type') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('activity_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="course_lecturer">Course Lecturer</label>
            <input type="text" name="course_lecturer" id="course_lecturer" list="ta-lecturers" required autocomplete="off"
                   placeholder="Search by name or staff code" value="{{ old('course_lecturer') }}"
                   class="@error('course_lecturer') is-invalid @enderror">
            <datalist id="ta-lecturers">
                @foreach ($lecturers as $lecturer)
                    <option value="{{ $lecturer->display() }}"></option>
                @endforeach
            </datalist>
            @error('course_lecturer') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Add Row</button>
        </form>

        <div class="ta-actions">
            @if ($drafts->isNotEmpty())
                <form method="POST" action="{{ route('ta-hours.submit', [$year, $month]) }}">
                    @csrf
                    <button type="submit">Submit {{ $drafts->count() }} {{ $drafts->count() === 1 ? 'row' : 'rows' }} ({{ $num($drafts->sum('hours')) }} hrs)</button>
                </form>
            @endif
            <a href="{{ route('ta-hours.index') }}" class="btn-secondary">Back</a>
            <a href="{{ route('dashboard') }}" class="btn-secondary">Exit</a>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var toggle = document.getElementById('ta-add-toggle');
            var form = document.getElementById('ta-add-row');
            if (! toggle || ! form) return;
            toggle.addEventListener('click', function () {
                form.hidden = ! form.hidden;
                toggle.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
                if (! form.hidden) document.getElementById('activity_date').focus();
            });
        })();
    </script>
@endpush
