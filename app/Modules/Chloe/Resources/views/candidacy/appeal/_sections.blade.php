{{--
    Read-only Section A-D readout, shared by the approver queue and the
    student's own show() page so the two never drift apart. Expects $detail
    (a CandidacyAppealDetail with candidacy/supervisor/publications loaded).
--}}
<dl class="rpd-facts">
    <div>
        <dt>Extension requested (D)</dt>
        <dd>{{ $detail->requested_extension_months }} {{ Str::plural('month', $detail->requested_extension_months) }}</dd>
    </div>
    <div>
        <dt>Current expiry</dt>
        <dd>{{ $detail->candidacy->candidacy_expiry_date->format('j M Y') }}</dd>
    </div>
    <div>
        <dt>Phase (A)</dt>
        <dd>
            {{ $detail->phaseLabel() }}
            @if ($detail->writing_completion_percent !== null)
                ({{ $detail->writing_completion_percent }}%)
            @endif
        </dd>
    </div>
    <div>
        <dt>Supervisor</dt>
        <dd>{{ $detail->supervisor->name ?? '—' }}</dd>
    </div>
</dl>

<p><b>Section A, Research Completion Seminar:</b>
    @if ($detail->rcs_status === 'completed')
        completed {{ $detail->rcs_date?->format('j M Y') }}, category {{ $detail->rcs_category }}.
    @else
        not yet done.
        @if ($detail->rcs_expected_date) Expected {{ $detail->rcs_expected_date->format('j M Y') }}. @endif
    @endif
</p>

<p><b>Section B, prior extension:</b>
    through GSC {{ $detail->extension_via_gsc ? 'yes' : 'no' }},
    through the Vice Chancellor {{ $detail->extension_via_vc ? 'yes' : 'no' }}.
</p>

@if ($detail->publications->isNotEmpty())
    <p><b>Section C, publications:</b></p>
    <ul>
        @foreach ($detail->publications as $publication)
            <li>{{ $publication->description }}</li>
        @endforeach
    </ul>
@endif

@if ($detail->reason)
    <p><b>Additional comments:</b></p>
    <div class="rpd-justification">{{ $detail->reason }}</div>
@endif
