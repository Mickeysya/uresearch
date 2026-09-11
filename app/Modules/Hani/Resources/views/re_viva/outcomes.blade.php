@extends('core::layouts.app')

@section('title', 'Re-viva Outcomes')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Re-viva Outcomes</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            Consolidation is scheduled; record the outcome level to close out the cycle.
            Level 4 lets the student open another cycle by uploading again; level 5 is terminal.
        </p>

        @forelse ($details as $detail)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $detail->application_id }}</b> — Cycle {{ $detail->cycle_number }}</p>
                </div>
                <p>Student: {{ $detail->application->student->name }}</p>
                <p>Resubmitted: {{ $detail->resubmission_at->format('j M Y') }}</p>

                @if ($detail->application->documents->isNotEmpty())
                    <ul class="doc-list">
                        @foreach ($detail->application->documents as $doc)
                            <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('reviva.outcomes.record', $detail) }}">
                    @csrf

                    <label for="outcome_level-{{ $detail->id }}">Outcome Level</label>
                    <select name="outcome_level" id="outcome_level-{{ $detail->id }}" required>
                        <option value="">— Select —</option>
                        <option value="1">Level 1 — Pass, minor corrections</option>
                        <option value="2">Level 2 — Pass, moderate corrections</option>
                        <option value="3">Level 3 — Pass, major corrections</option>
                        <option value="4">Level 4 — Fail, loop back to another cycle</option>
                        <option value="5">Level 5 — Dismissed</option>
                    </select>

                    <label for="outcome_remarks-{{ $detail->id }}">Remarks <span style="color: var(--text-grey)">(optional)</span></label>
                    <textarea id="outcome_remarks-{{ $detail->id }}" name="outcome_remarks" rows="2"></textarea>

                    <button type="submit">Record Outcome</button>
                </form>
            </div>
        @empty
            <div class="empty-state">No consolidated cycles awaiting an outcome.</div>
        @endforelse
    </div>
</div>
@endsection
