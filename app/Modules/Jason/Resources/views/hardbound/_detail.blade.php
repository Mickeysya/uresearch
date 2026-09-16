@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Thesis: <b>{{ $detail->thesis_title }}</b></p>
    <p>Matric: {{ $detail->matric_no ?: '—' }} &middot; Programme: {{ $detail->programme }}</p>
    <p>Supervisor: {{ $detail->supervisor_name }}@if ($detail->co_supervisor_name) &middot; Co-supervisor: {{ $detail->co_supervisor_name }}@endif</p>
    <p>Viva: {{ $detail->viva_date?->format('j M Y') ?? '—' }}</p>

    @if ($detail->isResubmission())
        <p style="margin-top: 10px;">
            <b>Resubmission of #{{ $detail->resubmission_of_id }}</b>
        </p>
        @if ($detail->response_to_comments)
            <p>Student's response: <i>{{ $detail->response_to_comments }}</i></p>
        @endif
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
