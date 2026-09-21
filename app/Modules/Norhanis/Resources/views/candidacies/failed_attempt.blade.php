@extends('core::layouts.app')

@section('title', 'Record Failed RPD Attempt')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Record Failed RPD Attempt</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            Enter the outcome here once it reaches CGS from the Academic Executive.
            Recording a failure computes the resubmission deadline for the student's
            programme and study mode and reopens their candidacy for that attempt.
        </p>

        @forelse ($candidacies as $candidacy)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>{{ $candidacy->student->name }}</b>
                        @if ($candidacy->student->matric_no) ({{ $candidacy->student->matric_no }}) @endif
                    </p>
                    <span class="queue-meta">
                        Attempt #{{ $candidacy->attempt_number }}
                    </span>
                </div>
                <p>Programme: {{ $candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }}</p>
                <p>Study Mode: {{ $candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}</p>
                <p>
                    @if ($candidacy->status === \App\Modules\Norhanis\Models\Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION)
                        Current Resubmission Deadline: {{ $candidacy->resubmission_deadline->format('j M Y') }}
                    @else
                        Current Deadline: {{ $candidacy->deadline->format('j M Y') }}
                    @endif
                </p>

                <form method="POST" action="{{ route('candidacy.failed-attempt.store', $candidacy) }}" style="margin-top: 12px;">
                    @csrf

                    <label for="notes-{{ $candidacy->id }}">Notes (optional)</label>
                    <textarea name="notes" id="notes-{{ $candidacy->id }}" rows="2"></textarea>

                    <button type="submit" class="btn-secondary" style="margin-top: 8px;">
                        Record Failed Attempt
                    </button>
                </form>
            </div>
        @empty
            <div class="empty-state">No candidacies are currently eligible to record an attempt result for.</div>
        @endforelse
    </div>
</div>
@endsection
