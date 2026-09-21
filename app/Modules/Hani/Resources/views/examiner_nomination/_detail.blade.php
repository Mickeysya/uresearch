@php($nomination = $nominations[$application->id] ?? null)

@if ($nomination)
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
@else
    <p style="color: var(--text-grey);">Nomination record missing for this application.</p>
@endif
