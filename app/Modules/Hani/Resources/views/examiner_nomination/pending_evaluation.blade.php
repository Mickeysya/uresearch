@extends('core::layouts.app')

@section('title', 'Examiner Nomination: Pending Evaluation')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Pending Evaluation"
        subtitle="Released panels in your department whose evaluation is not yet marked complete. Mark it complete once the viva has happened: the two main examiners start the {{ \App\Modules\Hani\Models\Examiner::GAP_DAYS }}-day cooling-off period from today, and any backup still held is freed without one." />

    <div class="card card-wide">
        @forelse ($nominations as $nomination)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $nomination->application_id }}</b></p>
                </div>
                <p>Student: {{ $nomination->application->student->name }}</p>
                <p>Thesis: {{ $nomination->thesis_title }}</p>
                @foreach ($nomination->panel() as $seat => $examiner)
                    @if (! $examiner)
                        @if (str_contains($seat, 'main'))
                            <p class="message-warning">{{ $seat }}: empty after a decline. Appoint a replacement from the panel page.</p>
                        @endif
                        @continue
                    @endif
                    <p>{{ $seat }}: <b>{{ $examiner->name }}</b>
                        @if ($examiner->assigned_until?->isPast())
                            <span class="message-warning" style="display: block;">Tie-up ended on {{ $examiner->assigned_until->format('j M Y') }} without the evaluation being marked complete. The examiner shows as available again but their cooling-off period has not started.</span>
                        @elseif ($examiner->assigned_until)
                            tied up until {{ $examiner->assigned_until->format('j M Y') }}
                        @elseif (str_contains($seat, 'backup'))
                            (standby)
                        @endif
                    </p>
                @endforeach

                <p><a href="{{ route('examiner-panel.show', $nomination) }}">Manage panel (declines and replacements)</a></p>

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
