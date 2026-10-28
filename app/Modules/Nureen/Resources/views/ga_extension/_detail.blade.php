@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Current End Date: {{ $detail->current_end_date->format('j M Y') }}</p>
    <p>Requested New End Date: <b>{{ $detail->requested_new_end_date->format('j M Y') }}</b>
       ({{ $detail->current_end_date->diffInDays($detail->requested_new_end_date) }} days extra)</p>
    <p>Reason: {{ $detail->reason_for_extension }}</p>

    @if (! empty($detail->additional_info))
        @foreach (config('nureen.ga_extension.additional_fields') as $key => $field)
            @continue(empty($detail->additional_info[$key]))
            <p>{{ $field['label'] }}: {{ $detail->additional_info[$key] }}</p>
        @endforeach
    @endif

    {{--
        What GaExtensionController::store() already enforced before this
        application could exist, shown back so CGS can see it was checked
        rather than take it on faith -- every type here was required at
        submission, so in the ordinary case this reads all confirmed. It
        still runs the real count, not a hardcoded tick, so it stays honest
        if a document is ever removed after the fact.
    --}}
    <div style="margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border-subtle);">
        <p style="margin: 0 0 var(--space-2); font-weight: var(--weight-semi); color: var(--text-dark);">
            System checks
        </p>
        <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-1);">
            @php($byType = $application->documents->groupBy('doc_type'))
            @foreach (config('nureen.ga_extension.required_documents') as $label)
                @php($count = $byType->get($label, collect())->count())
                <li style="color: {{ $count > 0 ? 'var(--success-fg)' : 'var(--danger-fg)' }};">
                    {{ $count > 0 ? '✓' : '✗' }}
                    {{ $label }}
                    @if ($count > 0)
                        ({{ $count }} file{{ $count === 1 ? '' : 's' }})
                    @else
                        (missing)
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
