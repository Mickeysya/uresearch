{{-- Softbound-specific lines inside the shared queue card (Form #019). --}}
@php
    use App\Modules\Hani\Models\VivaSoftboundDetail;
    use App\Modules\Hani\Support\VivaProgress;

    $detail = $details[$application->id] ?? null;
@endphp

@if ($detail)
    @if ($detail->thesis_title)
        <p>Thesis: {{ $detail->thesis_title }}</p>
    @endif
    @if ($detail->session)
        <p>Session: {{ $detail->session }} {{ $detail->year }}
           @if ($detail->field_of_study) &middot; Field: {{ $detail->field_of_study }} @endif</p>
    @endif
    <p>Similarity: <b>{{ $detail->similarity_percent }}%</b>
       <span class="status-badge {{ $detail->similarity_source === 'extracted' ? 'approved' : 'draft' }}">{{ match ($detail->similarity_source) {
           'extracted' => 'Read from the report', 'simulated' => 'Simulated reading', default => 'Self-declared',
       } }}</span>
       &middot; Words: <b>{{ number_format($detail->word_count) }}</b></p>
    @if ($detail->contact_number)
        <p>Contact: {{ $detail->contact_number }} &middot; {{ $detail->personal_email }}</p>
    @endif
    @if ($detail->co_supervisor_email)
        <p>Co-supervisor: {{ $detail->co_supervisor_email }}</p>
    @endif
    @include('hani::viva._system_checks', ['check' => ($checks ?? collect())->get($application->id)])
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
