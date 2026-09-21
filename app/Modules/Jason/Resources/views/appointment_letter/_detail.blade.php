@php
    $detail = $details[$application->id] ?? null;
    $panel = $examiners[$application->id] ?? collect();
@endphp

@if ($detail)
    <p>Nominated by: {{ $application->submittedBy?->name ?? 'Unknown' }} (Chair of Department)</p>

    <p style="margin-top: 8px;"><b>Examiner panel</b>: {{ $panel->count() }} {{ Str::plural('examiner', $panel->count()) }}</p>
    <ul style="margin: 4px 0 10px 18px;">
        @foreach ($panel->sortBy(fn ($e) => $e->isInternal() ? 0 : 1) as $examiner)
            <li>
                <b>{{ $examiner->examiner_name }}</b>: {{ $examiner->typeLabel() }},
                {{ $examiner->examiner_institution }}
                <span style="color: var(--text-grey);">({{ $examiner->examiner_email }})</span>
            </li>
        @endforeach
    </ul>

    @if ($detail->isPrepared())
        <p>
            Pack prepared {{ $detail->letter_prepared_at->format('j F Y') }}.
            {{ $panel->count() * 2 }} documents are attached below, two per examiner.
        </p>
        <p>Degree: {{ $detail->candidate_degree }} &middot; Programme: {{ $detail->candidate_programme }}</p>
        <p>Supervisor: {{ $detail->supervisor_name }}</p>
        <p>Thesis: <i>{{ $detail->thesis_title }}</i></p>
    @elseif ($stage->key === 'cgs_prep')
        <p style="margin-top: 12px;">
            <a href="{{ route('appointment-letter.prepare', $application) }}">
                <b>Prepare the appointment pack &rarr;</b>
            </a>
        </p>
        <p class="queue-meta">
            The letters are filled in from the candidate's record. Preparing generates
            {{ $panel->count() * 2 }} documents and sends them to the Dean.
            Use the form below only to reject the nomination.
        </p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
