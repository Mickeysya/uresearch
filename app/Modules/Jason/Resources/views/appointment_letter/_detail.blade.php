@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Nominated by: {{ $application->submittedBy?->name ?? 'Unknown' }} (Chair of Department)</p>
    <p>Examiner: <b>{{ $detail->examiner_name }}</b> — {{ $detail->examiner_institution }}</p>
    <p>Examiner email: {{ $detail->examiner_email }}</p>
    <p>Expertise: {{ $detail->examiner_expertise }}</p>

    @if ($detail->isPrepared())
        <p>
            Letter prepared {{ $detail->letter_prepared_at->format('j F Y') }} —
            {{ $detail->isInternal() ? 'Internal' : 'External' }} Examiner, ref {{ $detail->letter_ref_no }}
        </p>
        <p>Degree: {{ $detail->candidate_degree }} &middot; Programme: {{ $detail->candidate_programme }}</p>
        <p>Supervisor: {{ $detail->supervisor_name }}</p>
        <p>Thesis: <i>{{ $detail->thesis_title }}</i></p>
        <ul class="doc-list">
            <li><a href="{{ route('appointment-letter.letter', $application) }}" target="_blank">
                Read the prepared letter (PDF)
            </a></li>
        </ul>
    @elseif ($stage->key === 'cgs_prep')
        <p style="margin-top: 12px;">
            <a href="{{ route('appointment-letter.prepare', $application) }}">
                <b>Prepare the appointment letter &rarr;</b>
            </a>
        </p>
        <p class="queue-meta">
            The letter is filled in from the candidate's record — preparing it sends it to the Dean.
            Use the form below only to reject the nomination.
        </p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
