@extends('core::layouts.app')

@section('title', 'Upcoming RPD Reminders')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Upcoming RPD Reminders</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            Every active candidacy currently inside its 3/2/1-month reminder window, and whether
            that reminder has gone out yet. This mirrors what <code>rpd:remind</code> sends
            automatically at 07:00 daily -- "Send Now" is a manual fallback, not a replacement.
        </p>

        @forelse ($rows as $row)
            @php($candidacy = $row['candidacy'])
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>{{ $candidacy->student->name }}</b>
                        @if ($candidacy->student->matric_no) ({{ $candidacy->student->matric_no }}) @endif
                    </p>
                    <span class="queue-meta">{{ $row['month_mark'] }}-Month Mark</span>
                </div>
                <p>Programme: {{ $candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }}</p>
                <p>Study Mode: {{ $candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}</p>
                <p>Current Deadline: {{ $candidacy->deadline->format('j M Y') }}</p>

                @if ($row['sent_at'])
                    <p style="color: var(--green, #2e7d32); font-weight: 600; margin-top: 8px;">
                        &#10003; Sent {{ $row['sent_at']->format('j M Y, g:ia') }}
                    </p>
                @else
                    <form method="POST" action="{{ route('rpd-reminders.send', $candidacy) }}" style="margin-top: 12px;">
                        @csrf
                        <input type="hidden" name="month_mark" value="{{ $row['month_mark'] }}">
                        <button type="submit" class="btn-secondary">Send Now</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="empty-state">No candidacies are currently inside a reminder window.</div>
        @endforelse
    </div>
</div>
@endsection
