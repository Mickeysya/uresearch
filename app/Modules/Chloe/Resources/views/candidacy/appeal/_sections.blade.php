{{--
    Read-only Section A-D readout, shared by the approver queue and the
    student's own show() page so the two never drift apart. Expects $detail
    (a CandidacyAppeal with candidacy/supervisorProfile/department/publications loaded).

    Two different bolds on purpose: .app-item b (layout.css) colors every
    <b> navy, which is right for the "Section X" headings but wrong for a
    plain field label sitting right next to one -- so a field label pins
    itself back to ordinary body text with an inline override rather than
    inheriting the heading's colour.
--}}
@php($label = 'color: var(--text-dark);')

<p><b style="{{ $label }}">Supervisor / Programme Manager:</b> {{ $detail->supervisorProfile->full_name ?? '—' }}</p>
<p><b style="{{ $label }}">Department:</b> {{ $detail->department->name ?? '—' }}</p>
<p><b style="{{ $label }}">Current candidacy expiry:</b> {{ ($detail->expiry_at_submission ?? $detail->candidacy?->candidacy_expiry_date)?->format('j M Y') ?? '—' }}</p>
<p><b style="{{ $label }}">Reason for appeal:</b> {{ $detail->reason ?? '—' }}</p>

<p><b>Section A: Academic Progress / Current Status</b></p>
<p><b style="{{ $label }}">Phase:</b> {{ (\App\Modules\Chloe\Models\CandidacyAppeal::phases()[$detail->phase] ?? '—') }}
    @if ($detail->percent_complete !== null)
        ({{ $detail->percent_complete }}% complete)
    @endif
</p>
<p><b style="{{ $label }}">RCS:</b>
    @if (in_array($detail->rcs_status, ['completed', 'done'], true))
        Completed {{ $detail->rcs_date?->format('j M Y') }}, category {{ $detail->rcs_category }}
    @else
        Not yet done @if ($detail->rcs_expected_date), expected {{ $detail->rcs_expected_date->format('j M Y') }} @endif
    @endif
</p>

<p><b>Section B: Study Candidacy Extension Record</b></p>
<p><b style="{{ $label }}">Prior extension:</b> Through GSC: {{ $detail->gsc_extension ? 'Yes' : 'No' }},
    Through Vice Chancellor: {{ $detail->vc_extension ? 'Yes' : 'No' }}</p>

@if ($detail->publications->isNotEmpty())
    <p><b>Section C: List of Publications/Journal</b></p>
    <ul>
        @foreach ($detail->publications as $publication)
            <li>{{ $publication->title ? trim($publication->title.($publication->venue ? ', '.$publication->venue : '').' '.($publication->year ?: '')) : $publication->description }}</li>
        @endforeach
    </ul>
@endif

<p><b>Section D: Duration of Extension Requested</b></p>
<p><b style="{{ $label }}">Extension requested:</b> {{ $detail->requested_months }} month(s)</p>
