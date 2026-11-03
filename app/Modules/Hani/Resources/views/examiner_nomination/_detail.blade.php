@php($nomination = $nominations[$application->id] ?? null)

@if ($nomination)
    @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::nomination($application)])

    <p>Filed by: {{ $application->submittedBy?->name ?? 'Unknown' }}</p>
    <p>Thesis: {{ $nomination->thesis_title }}</p>

    <table class="recent-activity-table">
        <thead>
            <tr><th>Seat</th><th>Examiner</th><th>Department / Institution</th><th>State</th></tr>
        </thead>
        <tbody>
            @foreach ($nomination->panel() as $seat => $examiner)
                <tr>
                    <td>{{ $seat }}</td>
                    <td>{{ $examiner?->name ?? '—' }}</td>
                    <td>{{ $examiner?->institution ?: $examiner?->department ?: '—' }}</td>
                    <td>{{ $examiner?->stateLabel() ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($nomination->notes)
        <p>Notes: {{ $nomination->notes }}</p>
    @endif

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
