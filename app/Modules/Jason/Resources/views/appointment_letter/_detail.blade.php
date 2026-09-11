@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Nominated by: {{ $application->submittedBy?->name ?? 'Unknown' }} (Chair of Department)</p>
    <p>Examiner: <b>{{ $detail->examiner_name }}</b> — {{ $detail->examiner_institution }}</p>
    <p>Examiner email: {{ $detail->examiner_email }}</p>
    <p>Expertise: {{ $detail->examiner_expertise }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
