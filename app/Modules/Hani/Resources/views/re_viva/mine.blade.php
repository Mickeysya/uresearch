@extends('core::layouts.app')

@section('title', 'My Re-viva')

@section('content')
{{-- The student's view. Outcome levels and deadlines only: no examiner
     names, no individual recommendations, no staff-only files. --}}
<div class="card-container-inline">
    <x-core::page-header
        title="My Re-viva"
        subtitle="Each re-viva round, where it stands and what you need to do next." />

    @if ($expecting)
        <div class="card card-wide">
            <h3>Corrected thesis due to CGS by {{ $expecting->closes?->format('j M Y') }}</h3>
            <p>Prepare your Summary of Corrections in UResearch: the system checks every comment has an action and the pages exist in your corrected thesis, then produces the PDF for your supervisor to endorse.</p>
            @if ($summary?->submitted_at)
                <p class="message-success">Summary of Corrections ready since {{ $summary->submitted_at->format('j M Y') }}.</p>
            @endif
            <a href="{{ route('reviva.summary.edit') }}" class="btn">{{ $summary ? 'Open your Summary of Corrections' : 'Start your Summary of Corrections' }}</a>
        </div>
    @endif

    @forelse ($rounds as $round)
        <div class="card card-wide">
            <h3>Re-viva round {{ $round->cycle_number }}</h3>
            @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($round), 'compact' => true])

            <p>Corrected thesis logged by CGS: {{ $round->resubmission_at->format('j M Y') }}
                @if ($round->windowLabel()) <span class="fstep-hint">(resubmission window {{ $round->windowLabel() }})</span> @endif
            </p>

            @if ($round->hasOutcome())
                <p><b>Outcome: {{ $round->outcomeLabel() }}</b></p>

                @if ($round->needsFinalThesis())
                    @if ($round->corrections_accepted_at)
                        <p class="message-success">Your final thesis was accepted on {{ $round->corrections_accepted_at->format('j M Y') }}. Your re-viva is complete.</p>
                    @else
                        <p>Final thesis due: <b>{{ $round->correction_deadline?->format('j M Y') }}</b>
                            @if ($round->finalThesisOverdue()) <span class="status-badge rejected">Overdue</span> @endif
                        </p>
                        @if ($round->final_thesis_submitted_at)
                            <p class="fstep-hint">Submitted {{ $round->final_thesis_submitted_at->format('j M Y') }}. Waiting for your Academic Executive to accept it. You can upload a newer version below.</p>
                        @endif
                        <form method="POST" action="{{ route('reviva.final-thesis.store', $round) }}" enctype="multipart/form-data">
                            @csrf
                            <label for="final_thesis-{{ $round->id }}">Final thesis (PDF)</label>
                            <input type="file" name="final_thesis" id="final_thesis-{{ $round->id }}" accept="application/pdf,.pdf" required>
                            @error('final_thesis') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit">Upload Final Thesis</button>
                        </form>
                    @endif
                @elseif ($round->requiresLoopBack())
                    <p>An oral examination is required. Submit your corrected thesis to CGS by {{ \App\Modules\Hani\Support\ReVivaOutcome::nextRoundCloses($round)->format('j M Y') }}; CGS logs the next round.</p>
                @else
                    <p>This is the final outcome. Contact your Academic Executive if you have questions.</p>
                @endif
            @else
                <p class="fstep-hint">The examiners are re-evaluating your corrected thesis. You will be told the outcome here and by email.</p>
            @endif

            @if ($round->application->documents->isNotEmpty())
                <ul class="doc-list">
                    @foreach ($round->application->documents as $doc)
                        <li><a href="{{ route('documents.show', $doc) }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        @unless ($expecting)
            <div class="card card-wide"><div class="empty-state">You have no re-viva rounds.</div></div>
        @endunless
    @endforelse
</div>
@endsection
