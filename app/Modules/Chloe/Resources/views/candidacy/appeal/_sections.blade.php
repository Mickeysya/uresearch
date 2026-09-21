{{--
    Read-only Section A-D readout, shared by the approver queue and the
    student's own show() page so the two never drift apart. Expects $detail
    (a CandidacyAppealDetail with candidacy/supervisor/publications loaded).
--}}
<p>Supervisor selected: {{ $detail->supervisor->name ?? '—' }}</p>
<p>Current candidacy expiry: {{ $detail->candidacy->candidacy_expiry_date->format('j M Y') }}</p>

<p><b>Section A</b> — Phase: {{ $detail->phaseLabel() }}
    @if ($detail->writing_completion_percent !== null)
        ({{ $detail->writing_completion_percent }}% complete)
    @endif
</p>
<p>RCS:
    @if ($detail->rcs_status === 'completed')
        Completed {{ $detail->rcs_date?->format('j M Y') }}, category {{ $detail->rcs_category }}
    @else
        Not yet done @if ($detail->rcs_expected_date) — expected {{ $detail->rcs_expected_date->format('j M Y') }} @endif
    @endif
</p>

<p><b>Section B</b> — Prior extension: Through GSC: {{ $detail->extension_via_gsc ? 'Yes' : 'No' }},
    Through Vice Chancellor: {{ $detail->extension_via_vc ? 'Yes' : 'No' }}</p>

@if ($detail->publications->isNotEmpty())
    <p><b>Section C</b> — Publications/Journal:</p>
    <ul>
        @foreach ($detail->publications as $publication)
            <li>{{ $publication->description }}</li>
        @endforeach
    </ul>
@endif

<p><b>Section D</b> — Extension requested: {{ $detail->requested_extension_months }} month(s)</p>

@if ($detail->reason)
    <p>Additional comments: {{ $detail->reason }}</p>
@endif
