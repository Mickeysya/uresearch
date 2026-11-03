@include('nureen::charter._queue_clock')

@php
    $detail = $details[$application->id] ?? null;
    $viewer = auth()->user();
    $student = $application->student;
    $config = config('nureen.ga_extension');
    $byType = $application->documents->groupBy('doc_type');
    $ruleChecks = $detail ? app(\App\Modules\Nureen\Support\GaExtensionRules::class)->check($detail, $application, $application->documents) : [];
    $publications = $detail?->publicationRows() ?? [];
    // Section J: editable by the CGS stage's role while it is at that stage.
    $currentStage = app(\App\Modules\Core\Services\WorkflowEngine::class)->currentStage($application);
    $canFillJ = $currentStage?->key === 'cgs_verify' && $viewer?->role === $currentStage->role;
    $remarks = $detail?->cgs_remarks ?? [];
    $tone = [
        'pass' => ['✓', 'var(--success-fg)'],
        'fail' => ['✗', 'var(--danger-fg)'],
        'unknown' => ['?', 'var(--text-grey)'],
    ];
@endphp

@if ($detail)
    {{-- One CGS queue holds both chains' applications (see
         GaExtensionWorkflow::allStages()), so say which this one is. --}}
    @if ($application->current_stage === 'cgs_verify')
        <p class="queue-meta">
            @if ($detail->final_approver_snapshot === 'senior_director')
                Submitted under the earlier chain: your approval sends it on to the Senior Director CGS for the final decision.
            @else
                Your decision is final for this application.
            @endif
        </p>
    @endif

    <p>
        <a href="{{ route('ga-extension.form-pdf', $application) }}" class="btn-secondary">Download GA Extension Form (PDF)</a>
        <span class="queue-meta">Generated from this application as it stands now, endorsements so far included.</span>
    </p>

    <p>Current End Date: {{ $detail->current_end_date?->format('j M Y') ?? '—' }}</p>
    <p>Requested New End Date: <b>{{ $detail->requested_new_end_date?->format('j M Y') ?? '—' }}</b>
        @if ($detail->extensionSummary()) ({{ $detail->extensionSummary() }}) @endif</p>
    <p>Reason: {{ $detail->reason_for_extension }}</p>

    @if (! empty($detail->additional_info))
        @foreach ($config['additional_fields'] as $key => $field)
            @continue(empty($detail->additional_info[$key]))
            <p>{{ $field['label'] }}: {{ $detail->additional_info[$key] }}</p>
        @endforeach
    @endif

    {{-- Section B. Applications submitted before the form was generated
         have none of it. --}}
    @if ($detail->level_of_study || $detail->admission_date || $detail->contact_no)
        <dl class="ga-detail-grid">
            <dt>Programme</dt><dd>{{ $student?->programme ?? '—' }}</dd>
            <dt>Level / mode</dt><dd>{{ $detail->optionLabel('level_of_study') ?? '—' }} · {{ $detail->optionLabel('mode_of_study') ?? '—' }}</dd>
            <dt>Admission</dt><dd>{{ $detail->admission_date?->format('j M Y') ?? '—' }}@if ($detail->candidature_period) · candidature {{ $detail->candidature_period }}@endif</dd>
            <dt>Contact</dt><dd>{{ $detail->contact_no ?? '—' }} · {{ $detail->contact_email ?? '—' }}</dd>
            @if ($config['collect_bank_details'] || $detail->bank_name)
                <dt>Bank</dt><dd>{{ $detail->bank_name ?? '—' }} · {{ $detail->sensitiveFor('bank_account_no', $viewer) ?? '—' }}</dd>
            @endif
            <dt>IC / passport</dt><dd>{{ $detail->sensitiveFor('identity_number', $viewer) ?? '—' }}</dd>
            <dt>Visa</dt><dd>{{ $detail->holds_visa ? 'Expires '.($detail->visa_expiry_date?->format('j M Y') ?? '—') : ($detail->holds_visa === false ? 'Not applicable' : '—') }}</dd>
            @if ($detail->declaration_accepted_at)
                <dt>Declaration</dt><dd>Accepted {{ $detail->declaration_accepted_at->format('j M Y, g:i a') }}</dd>
            @endif
        </dl>
    @endif

    @if ($publications !== [])
        <p style="margin-bottom: var(--space-1);"><b>Publications</b></p>
        <ol class="ga-detail-pubs">
            @foreach ($publications as $row)
                <li>
                    {{ $config['publications']['types'][$row['type'] ?? ''] ?? '' }}: {{ \App\Modules\Nureen\Models\GaExtensionDetail::citation($row) }}
                    @if (\App\Modules\Nureen\Models\GaExtensionDetail::databaseAndQuartile($row) !== '' || filled($row['issn'] ?? null) || filled($row['impact_factor'] ?? null))
                        <span class="queue-meta">({{ implode(' · ', array_filter([
                            \App\Modules\Nureen\Models\GaExtensionDetail::databaseAndQuartile($row),
                            filled($row['issn'] ?? null) ? 'ISSN '.$row['issn'] : null,
                            filled($row['impact_factor'] ?? null) ? 'IF '.$row['impact_factor'] : null,
                        ])) }})</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    {{--
        Documents: what GaExtensionController::store() enforced, recounted
        here rather than assumed. Rules: the form's Terms and Conditions
        (Support\GaExtensionRules) -- they warn, they do not decide.
    --}}
    <div class="ga-checks">
        <p class="ga-checks-title">System checks</p>
        <ul>
            @foreach ($config['required_documents'] as $label)
                @php($count = $byType->get($label, collect())->count())
                <li style="color: {{ $count > 0 ? 'var(--success-fg)' : 'var(--danger-fg)' }};">
                    {{ $count > 0 ? '✓' : '✗' }} {{ $label }}
                    {{ $count > 0 ? '('.$count.' file'.($count === 1 ? '' : 's').')' : '(missing)' }}
                </li>
            @endforeach
            @foreach ([...array_values($config['stage_gate_documents']), ...array_values($config['legacy_documents'])] as $label)
                @continue(! $byType->has($label))
                <li style="color: var(--success-fg);">
                    ✓ {{ $label }} ({{ $byType->get($label)->count() }} file{{ $byType->get($label)->count() === 1 ? '' : 's' }})
                    @if ($label === $config['legacy_documents']['form'])
                        <span class="queue-meta">uploaded before the form was generated</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <ul style="margin-top: var(--space-2);">
            @foreach ($ruleChecks as $check)
                <li>
                    <span style="color: {{ $tone[$check['status']][1] }}; font-weight: var(--weight-semi);">{{ $tone[$check['status']][0] }} {{ $check['label'] }}</span>
                    <span class="queue-meta">{{ $check['reason'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Section J of the form: CGS staff fill it at their stage; it prints
         on the generated form. Anyone else sees what was saved. --}}
    @if ($canFillJ)
        <form method="POST" action="{{ route('ga-extension.cgs-remarks', $application) }}" class="ga-remarks">
            @csrf
            <p class="ga-checks-title">Section J: CGS use remarks</p>
            <div class="ga-remarks-grid">
                @foreach ($config['cgs_remarks_fields'] as $key => $field)
                    <div>
                        <label for="j-{{ $application->id }}-{{ $key }}">{{ $field['label'] }}</label>
                        <input type="{{ ($field['type'] ?? 'text') === 'date' ? 'date' : 'text' }}" maxlength="255"
                               name="cgs_remarks[{{ $key }}]" id="j-{{ $application->id }}-{{ $key }}"
                               value="{{ old("cgs_remarks.$key", $remarks[$key] ?? '') }}">
                    </div>
                @endforeach
            </div>
            <button type="submit" class="btn-secondary">Save Section J</button>
            @if ($detail->cgs_remarks_at)
                <span class="queue-meta">Last saved {{ $detail->cgs_remarks_at->format('j M Y, g:i a') }}{{ $detail->cgsRemarksBy ? ' by '.$detail->cgsRemarksBy->name : '' }}.</span>
            @endif
        </form>
    @elseif (array_filter($remarks, 'filled') !== [])
        <div class="ga-remarks">
            <p class="ga-checks-title">Section J: CGS use remarks</p>
            <dl class="ga-detail-grid">
                @foreach ($config['cgs_remarks_fields'] as $key => $field)
                    @continue(blank($remarks[$key] ?? null))
                    <dt>{{ $field['label'] }}</dt><dd>{{ $remarks[$key] }}</dd>
                @endforeach
            </dl>
        </div>
    @endif

    @once
        <style>
            .ga-detail-grid { display: grid; grid-template-columns: max-content 1fr; gap: var(--space-1) var(--space-4); margin: var(--space-3) 0; }
            .ga-detail-grid dt { color: var(--text-grey); }
            .ga-detail-grid dd { margin: 0; overflow-wrap: anywhere; }
            .ga-detail-pubs { margin: 0 0 var(--space-3); padding-left: var(--space-5); }
            .ga-checks, .ga-remarks { margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border-subtle); }
            .ga-checks ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-1); }
            .ga-checks .queue-meta { display: block; margin: 0 0 0 1.3em; }
            .ga-checks-title { margin: 0 0 var(--space-2); font-weight: var(--weight-semi); color: var(--text-dark); }
            .ga-remarks-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 var(--space-4); margin-bottom: var(--space-3); }
        </style>
    @endonce
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
