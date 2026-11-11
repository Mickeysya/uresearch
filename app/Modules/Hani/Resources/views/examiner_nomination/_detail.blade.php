@php($nomination = $nominations[$application->id] ?? null)

@if ($nomination)
    @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::nomination($application)])

    <p>Filed by: {{ $application->submittedBy?->name ?? 'Unknown' }}</p>
    @if ($dac = \App\Modules\Hani\Models\ExaminerDacRecord::endorsementFor($application->id))
        <p><span class="status-badge approved">{{ $dac->label() }}</span>
            @if ($dac->minutes_path) <a href="{{ route('examiner-nomination.dac.minutes', $dac) }}" target="_blank" rel="noopener">Minutes</a> @endif</p>
    @endif
    <p>Thesis: {{ $nomination->thesis_title }}</p>

    <table class="recent-activity-table">
        <thead>
            <tr><th>Seat</th><th>Examiner</th><th>Department / Institution</th><th>State</th></tr>
        </thead>
        <tbody>
            @foreach ($nomination->panel() as $seat => $examiner)
                <tr>
                    <td>{{ $seat }}</td>
                    <td>
                        {{ $examiner?->name ?? '—' }}
                        @include('hani::examiner_nomination._coi', ['flags' => $examiner ? ($coiFlags[$application->id][$examiner->id] ?? []) : []])
                    </td>
                    <td>{{ $examiner?->institution ?: $examiner?->department ?: '—' }}</td>
                    <td>{{ $examiner?->stateLabel() ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (collect($coiFlags[$application->id] ?? [])->flatten(1)->isNotEmpty())
        <p class="coi-note" style="color: var(--text-grey); font-size: var(--text-xs);">{{ \App\Modules\Hani\Support\ConflictOfInterest::NOTE }}</p>
    @endif

    @if ($nomination->notes)
        <p>Notes: {{ $nomination->notes }}</p>
    @endif

    @foreach ($substitutions[$nomination->id] ?? [] as $substitution)
        <p class="message-warning">
            Substitution requested: {{ $substitution->seatLabel() }} ({{ $substitution->examiner?->name }}). {{ $substitution->reason }}
            @if (auth()->user()->role === \App\Modules\Core\Support\Role::ACADEMIC_EXEC)
                <a href="{{ route('examiner-substitution.show', $substitution) }}">Replace this seat</a>
            @endif
        </p>
    @endforeach

    @foreach ($duplicates[$application->id] ?? [] as $flag)
        <p class="message-warning">Possible duplicate: {{ $flag }}</p>
    @endforeach

    {{-- Core's decision form always reads "Approve"; until it takes a label
         (app/Modules/Hani/SHARED_CHANGES.md), this says what Approve does here.
         See ExaminerNominationController::finaliseAndRelease(). --}}
    @if ($application->current_stage === \App\Modules\Hani\Http\Controllers\ExaminerNominationController::FINALISE_STAGE)
        <p class="message-warning">
            Approving this stage finalises the list AND releases it to CGS operations (two steps recorded).
        </p>
    @endif
@else
    <p style="color: var(--text-grey);">Nomination record missing for this application.</p>
@endif
