{{-- Viva-specific lines inside the shared queue card. --}}
@php
    $detail = $details[$application->id] ?? null;
@endphp

@if ($detail)
    <p>Thesis: {{ $detail->thesis_title }}</p>
    <p>Programme: <b>{{ $detail->programme_level === \App\Modules\Hani\Support\VivaChecklist::PHD ? 'PhD' : 'MSc by Research' }}</b>
       &middot; {{ $application->student?->programme }}</p>
    <p>Expected softbound: {{ $detail->expected_softbound_date->format('j M Y') }}</p>
    @if ($detail->keywords)
        <p>Keywords: {{ implode('; ', $detail->keywords) }}</p>
    @endif

    {{-- What the notice was filed on. Acknowledging confirms it. --}}
    @php
        $record = ($eligibility ?? collect())->get($application->student_id);
        $results = $record?->systemResults() ?? [];
        $student = $application->student;
        $items = $student ? \App\Modules\Hani\Support\VivaChecklist::itemsFor($student) : [];
    @endphp
    @if ($items)
        <p><b>Eligibility</b>
            @if ($record?->verified_at)
                &middot; confirmed by {{ $record->verifiedBy?->name }} on {{ $record->verified_at->format('j M Y') }}
            @else
                &middot; Acknowledging confirms these.
            @endif
        </p>
        <ul class="fstep-hint">
            @foreach ($items as $column => $item)
                @php
                    $check = $results[$column] ?? \App\Modules\Hani\Support\Eligibility\EligibilityResult::selfDeclared();
                @endphp
                <li>
                    {{ $item['label'] }}:
                    @include('hani::viva._eligibility_badge', ['check' => $check])
                    @if ($check->message && $check->status !== 'self_declared') {{ $check->message }} @endif
                </li>
            @endforeach
        </ul>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
