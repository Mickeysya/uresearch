{{-- Dismissal-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Deadline missed: <b class="tone-critical">{{ $detail->deadline_missed_on->format('j M Y') }}</b>
       ({{ abs((int) now()->startOfDay()->diffInDays($detail->deadline_missed_on, false)) }} days ago)</p>
    @if ($detail->candidacy)
        <p>Programme: {{ \App\Modules\Norhanis\Models\Candidacy::programmeTypes()[$detail->candidacy->programme_type] ?? $detail->candidacy->programme_type }}
           &middot; started {{ $detail->candidacy->candidature_start_date->format('j M Y') }}</p>
        <p>Extension granted over the candidacy: {{ $detail->candidacy->extension_months_used }} months</p>
    @endif
    <p>Opened by: {{ $detail->initiator?->name ?? 'CGS' }}</p>
    <p class="rpd-justification">{{ $detail->grounds }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
