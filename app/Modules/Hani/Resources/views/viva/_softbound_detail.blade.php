{{-- Softbound-specific lines inside the shared queue card. --}}
@php
    use App\Modules\Hani\Models\VivaSoftboundDetail;
    use App\Modules\Hani\Support\VivaProgress;

    $detail = $details[$application->id] ?? null;
@endphp

@if ($detail)
    <p>Similarity: <b>{{ $detail->similarity_percent }}%</b>
       &middot; Words: <b>{{ number_format($detail->word_count) }}</b></p>
    <p>Declared: bilingual abstract, 2 certified copies, before candidacy ends</p>
    <p>Examiner nomination: <b>{{ VivaProgress::describeNomination($nominations[$application->student_id] ?? null) }}</b></p>
    @if ($detail->isEarly())
        <p class="message-warning">
            Filed {{ (int) $detail->vivaApplication->submitted_at->diffInDays($detail->created_at) }} days after the notice
            ({{ $detail->vivaApplication->submitted_at->format('j M Y') }}). The guideline asks for at least
            {{ VivaSoftboundDetail::NOTICE_LEAD_MONTHS }} months.
        </p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
