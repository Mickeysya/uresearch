@extends('core::layouts.app')

@section('title', 'Examiner Nomination — Pending Evaluation')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Pending Evaluation</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            Approved nominations whose examiners are still tied up. Mark an evaluation
            complete once the viva has actually happened — this clears the tie-up and
            starts the {{ \App\Modules\Hani\Models\Examiner::GAP_DAYS }}-day cooling-off period from today.
        </p>

        @forelse ($nominations as $nomination)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $nomination->application_id }}</b></p>
                </div>
                <p>Student: {{ $nomination->application->student->name }}</p>
                <p>Thesis: {{ $nomination->thesis_title }}</p>
                <p>Main examiner: <b>{{ $nomination->mainExaminer->name }}</b>
                    @if ($nomination->mainExaminer->assigned_until)
                        — tied up until {{ $nomination->mainExaminer->assigned_until->format('j M Y') }}
                    @endif
                </p>
                @if ($nomination->backupExaminer)
                    <p>Backup examiner: {{ $nomination->backupExaminer->name }}
                        @if ($nomination->backupExaminer->assigned_until)
                            — tied up until {{ $nomination->backupExaminer->assigned_until->format('j M Y') }}
                        @endif
                    </p>
                @endif

                <form method="POST" action="{{ route('examiner-nomination.mark-complete', $nomination) }}">
                    @csrf
                    <button type="submit">Mark Evaluation Complete</button>
                </form>
            </div>
        @empty
            <div class="empty-state">Nothing awaiting evaluation right now.</div>
        @endforelse
    </div>
</div>
@endsection
