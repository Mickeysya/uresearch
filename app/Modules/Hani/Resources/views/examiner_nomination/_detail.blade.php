@php($nomination = $nominations[$application->id] ?? null)

@if ($nomination)
    <p>Filed by: {{ $application->submittedBy?->name ?? 'Unknown' }}</p>
    <p>Thesis: {{ $nomination->thesis_title }}</p>
    <p>Main examiner: <b>{{ $nomination->mainExaminer->name }}</b>
       — {{ $nomination->mainExaminer->department }}
       ({{ ucfirst($nomination->mainExaminer->type) }})</p>
    @if ($nomination->backupExaminer)
        <p>Backup examiner: {{ $nomination->backupExaminer->name }}
           — {{ $nomination->backupExaminer->department }}
           ({{ ucfirst($nomination->backupExaminer->type) }})</p>
    @endif
    @if ($nomination->notes)
        <p>Notes: {{ $nomination->notes }}</p>
    @endif
@else
    <p style="color: var(--text-grey);">Nomination record missing for this application.</p>
@endif
