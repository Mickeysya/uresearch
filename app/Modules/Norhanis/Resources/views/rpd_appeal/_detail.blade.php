{{-- Appeal-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Requested: <b>{{ $detail->requested_months }} {{ Str::plural('month', $detail->requested_months) }}</b></p>
    <p>Deadline when filed: {{ $detail->deadline_at_filing->format('j M Y') }}
       → would become
       {{ $detail->deadline_at_filing->copy()->addMonthsNoOverflow($detail->requested_months)->format('j M Y') }}</p>
    @if ($detail->candidacy)
        <p>Programme: {{ \App\Modules\Norhanis\Models\Candidacy::programmeTypes()[$detail->candidacy->programme_type] ?? $detail->candidacy->programme_type }}</p>
        <p>Extension already used: {{ $detail->candidacy->extension_months_used }} of
           {{ \App\Modules\Norhanis\Models\Candidacy::MAX_EXTENSION_MONTHS }} months</p>
    @endif
    <p class="rpd-justification">{{ $detail->justification }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
