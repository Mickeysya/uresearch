@php
    $detail = $details[$application->id] ?? null;
    $original = $detail ? ($originals[$detail->hardbound_application_id] ?? null) : null;
@endphp

@if ($detail)
    <p>Appealing submission <b>#{{ $detail->hardbound_application_id }}</b></p>

    @if ($original)
        <p>Thesis: <i>{{ $original->thesis_title }}</i></p>
        <p>Programme: {{ $original->programme }} &middot; Supervisor: {{ $original->supervisor_name }}</p>
    @else
        <p style="color: var(--text-grey);">The original submission's details are missing.</p>
    @endif

    <p style="margin-top: 10px;">Grounds: {{ $detail->justification }}</p>

    @if ($detail->pfr_recommendation)
        <p><b>CGS recommendation:</b> {{ $detail->pfr_recommendation }}</p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this appeal.</p>
@endif
