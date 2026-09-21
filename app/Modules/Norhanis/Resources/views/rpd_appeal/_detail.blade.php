{{-- RPD Appeal-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail && $detail->candidacy)
    <p><b>Current Deadline:</b> {{ $detail->candidacy->deadline->format('j M Y') }}</p>
    <p><b>Extension Requested:</b> {{ $detail->requested_extension_months }} {{ $detail->requested_extension_months === 1 ? 'month' : 'months' }}
        (new deadline if approved: {{ $detail->candidacy->deadline->copy()->addMonths($detail->requested_extension_months)->format('j M Y') }})</p>
    <p><b>Programme:</b> {{ $detail->candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }}</p>
    <p><b>Study Mode:</b> {{ $detail->candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}</p>
    <p><b>Reason:</b> {{ $detail->reason }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
