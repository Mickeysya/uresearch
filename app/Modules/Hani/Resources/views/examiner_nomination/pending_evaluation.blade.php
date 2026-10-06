@extends('core::layouts.app')

@section('title', 'Examiner Nomination: Pending Evaluation')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Pending Evaluation"
        subtitle="Approved nominations whose examiners are still tied up. Mark an evaluation complete once the viva has actually happened. That clears the tie-up and starts the {{ \App\Modules\Hani\Models\Examiner::GAP_DAYS }}-day cooling-off period from today." />

    <div class="card card-wide">
        @forelse ($nominations as $nomination)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $nomination->application_id }}</b></p>
                </div>
                <p>Student: {{ $nomination->application->student->name }}</p>
                <p>Thesis: {{ $nomination->thesis_title }}</p>
                @foreach ($nomination->panel() as $seat => $examiner)
                    @continue (! $examiner)
                    <p>{{ $seat }}: <b>{{ $examiner->name }}</b>
                        @if ($examiner->assigned_until)
                            tied up until {{ $examiner->assigned_until->format('j M Y') }}
                        @endif
                    </p>
                @endforeach

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
