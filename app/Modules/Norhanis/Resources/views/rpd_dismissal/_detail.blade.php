{{-- RPD Dismissal-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@php($missedDeadline = $detail && $detail->candidacy && $detail->candidacy->status === \App\Modules\Norhanis\Models\Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION ? $detail->candidacy->resubmission_deadline : $detail?->candidacy?->deadline)

@if ($detail && $detail->candidacy)
    <p><b>Programme:</b> {{ $detail->candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }}</p>
    <p><b>Study Mode:</b> {{ $detail->candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}</p>
    <p><b>Deadline Missed:</b> {{ $missedDeadline->format('j M Y') }}</p>
    <p><b>Reason CGS Initiated Dismissal:</b> {{ $detail->reason }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
