@extends('core::layouts.app')

@section('title', 'Examiner Panel #'.$nomination->application_id)

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="'Examiner Panel: '.$student->name"
        :subtitle="'Application #'.$nomination->application_id.' · '.$nomination->thesis_title">
        <a href="{{ route('examiner-nomination.report') }}" class="btn-secondary">Examiner Report</a>
    </x-core::page-header>

    <div class="card card-wide" style="max-width: none;">
        <h3 style="margin-top: 0;">Current panel</h3>
        <p style="color: var(--text-grey); font-size: var(--text-sm);">
            Appointment letters go to the two main examiners only. Backups stay available on standby and are promoted automatically if a main examiner declines and they are still eligible.
        </p>

        <table class="data-table">
            <thead><tr><th>Seat</th><th>Examiner</th><th>State</th>@if ($lettersSent !== null)<th>Appointment letter</th>@endif<th></th></tr></thead>
            <tbody>
                @foreach (\App\Modules\Hani\Models\ExaminerNomination::SLOTS as $seat => $label)
                    @php($examiner = $nomination->{\Illuminate\Support\Str::camel(str_replace('_id', '', $seat))})
                    <tr id="seat-{{ $seat }}">
                        <td>{{ $label }}</td>
                        <td>
                            {{ $examiner?->name ?? 'Empty' }}
                            @if ($examiner?->emailNeedsUpdating())
                                <span class="coi-flag" style="display: block; font-size: var(--text-2xs); color: var(--warning-fg);">{{ $examiner->emailInvalid() ? 'Email needs updating' : 'Email needed' }} before a letter can be sent.</span>
                            @endif
                        </td>
                        <td>
                            @if ($examiner)
                                {{ $examiner->stateLabel() }}{{ array_key_exists($seat, \App\Modules\Hani\Support\PanelSeats::MAINS) ? '' : ' (standby)' }}
                            @else
                                —
                            @endif
                        </td>
                        @if ($lettersSent !== null)
                            <td>
                                @if (array_key_exists($seat, $lettersSent))
                                    {{ $lettersSent[$seat] ? 'Sent '.$lettersSent[$seat]->format('j M Y') : 'Not sent yet' }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                        <td>
                            @if ($examiner && array_key_exists($seat, \App\Modules\Hani\Support\PanelSeats::MAINS))
                                <details>
                                    <summary class="btn-secondary" style="display: inline-block; cursor: pointer;">Mark as declined</summary>
                                    <form method="POST" action="{{ route('examiner-panel.decline', $nomination) }}" style="margin-top: 8px;">
                                        @csrf
                                        <input type="hidden" name="seat" value="{{ $seat }}">
                                        <label for="reason-{{ $seat }}">Why did {{ $examiner->name }} decline?</label>
                                        <textarea name="reason" id="reason-{{ $seat }}" rows="2" required maxlength="1000"
                                                  class="@error('reason') is-invalid @enderror">{{ old('seat') === $seat ? old('reason') : '' }}</textarea>
                                        @if (old('seat') === $seat) @error('reason') <p class="field-error">{{ $message }}</p> @enderror @endif
                                        <button type="submit" class="btn-reject">Record the decline</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @foreach ($replacements as $seat => $options)
            <h3>Replacement for the {{ \App\Modules\Hani\Models\ExaminerNomination::SLOTS[$seat] }} seat</h3>

            @if ($options['suggested'])
                <p style="color: var(--text-grey); font-size: var(--text-sm);">Suggested from the eligible pool, best fit first:</p>
                <ol>
                    @foreach ($options['suggested'] as $s)
                        <li>
                            <b>{{ $s['name'] }}</b> ({{ $s['where'] }}), {{ $s['score'] }}% match
                            @if ($s['matched']) · matched on: {{ implode(', ', $s['matched']) }} @endif
                            <span style="display: block; color: var(--text-grey); font-size: var(--text-xs);">{{ implode(' · ', $s['reasons']) }}</span>
                            @include('hani::examiner_nomination._coi', ['flags' => $s['flags']])
                        </li>
                    @endforeach
                </ol>
            @endif

            <form method="POST" action="{{ route('examiner-panel.replace', $nomination) }}" class="app-form">
                @csrf
                <input type="hidden" name="seat" value="{{ $seat }}">
                <label for="examiner-{{ $seat }}">Appoint</label>
                <select name="examiner_id" id="examiner-{{ $seat }}" required class="@error('examiner_id') is-invalid @enderror">
                    <option value="">Select an eligible examiner</option>
                    @foreach ($options['eligible'] as $e)
                        <option value="{{ $e->id }}" @selected(old('examiner_id') == $e->id)>{{ $e->name }}, {{ $e->isExternal() ? $e->institution : $e->department }}</option>
                    @endforeach
                </select>
                @error('examiner_id') <p class="field-error">{{ $message }}</p> @enderror
                <button type="submit">Appoint replacement</button>
            </form>
        @endforeach

        <h3>History</h3>
        <table class="data-table">
            <thead><tr><th>When</th><th>Seat</th><th>Examiner</th><th>What happened</th><th>By</th></tr></thead>
            <tbody>
                @forelse ($history as $entry)
                    <tr>
                        <td>{{ $entry->created_at?->format('j M Y, g:i a') }}</td>
                        <td>{{ $entry->seatLabel() }}</td>
                        <td>{{ $entry->examiner?->name ?? '—' }}</td>
                        <td>{{ $entry->eventLabel() }}@if ($entry->reason): {{ $entry->reason }}@endif</td>
                        <td>{{ $entry->actor?->name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No changes recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
