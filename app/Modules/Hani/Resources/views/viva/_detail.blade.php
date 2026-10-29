{{-- Viva-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Thesis: {{ $detail->thesis_title }}</p>
    <p>Programme: <b>{{ $detail->programme_level === \App\Modules\Hani\Support\VivaChecklist::PHD ? 'PhD' : 'MSc by Research' }}</b>
       &middot; {{ $application->student?->programme }}</p>
    <p>Expected softbound: {{ $detail->expected_softbound_date->format('j M Y') }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
