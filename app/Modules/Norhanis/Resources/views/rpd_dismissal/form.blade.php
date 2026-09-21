@extends('core::layouts.app')

@section('title', 'Overdue RPD Candidacies')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Overdue RPD Candidacies</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            {{ $candidacies->count() }} {{ Str::plural('candidacy', $candidacies->count()) }} past their deadline
            (original or resubmission), with no dismissal case already in progress.
        </p>

        @forelse ($candidacies as $candidacy)
            @php($missedDeadline = $candidacy->status === \App\Modules\Norhanis\Models\Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION ? $candidacy->resubmission_deadline : $candidacy->deadline)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>{{ $candidacy->student->name }}</b>
                        @if ($candidacy->student->matric_no) ({{ $candidacy->student->matric_no }}) @endif
                    </p>
                    <span style="color: var(--red, #c0392b); font-weight: 600;">
                        {{ $missedDeadline->diffForHumans() }}
                    </span>
                </div>
                <p>Programme: {{ $candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }}</p>
                <p>Study Mode: {{ $candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}</p>
                <p>
                    {{ $candidacy->status === \App\Modules\Norhanis\Models\Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION ? 'Resubmission Deadline (missed)' : 'RPD Deadline (missed)' }}:
                    {{ $missedDeadline->format('j M Y') }}
                </p>

                <form method="POST" action="{{ route('rpd-dismissal.store') }}" style="margin-top: 12px;">
                    @csrf
                    <input type="hidden" name="candidacy_id" value="{{ $candidacy->id }}">

                    <label for="reason-{{ $candidacy->id }}">Reason for Initiating Dismissal</label>
                    <textarea name="reason" id="reason-{{ $candidacy->id }}" rows="3" required></textarea>

                    <button type="submit" class="btn-secondary" style="margin-top: 8px;">
                        Initiate Dismissal Case
                    </button>
                </form>
            </div>
        @empty
            <div class="empty-state">No candidacies are currently overdue for dismissal.</div>
        @endforelse
    </div>
</div>
@endsection
