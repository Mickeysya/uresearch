@extends('core::layouts.app')

@section('title', 'My Candidacy')

@section('content')
@php use App\Modules\Norhanis\Models\Candidacy; @endphp

<div class="card-container-inline">
    <x-core::page-header
        title="My RPD Candidacy" />

    <div class="card card-wide">
        @if (! $candidacy)
            <div class="empty-state">
                <p>No candidacy is on record for you yet.</p>
                <p class="queue-meta">CGS registers your candidature and its Research Proposal Defence deadline.</p>
            </div>
        @else
            <dl class="rpd-facts">
                <div>
                    <dt>RPD deadline</dt>
                    <dd class="tone-{{ $candidacy->tone() }}">{{ $candidacy->rpd_deadline->format('j M Y') }}</dd>
                </div>
                <div>
                    <dt>{{ $candidacy->isOverdue() ? 'Overdue by' : 'Time remaining' }}</dt>
                    <dd class="tone-{{ $candidacy->tone() }}">{{ abs($candidacy->daysRemaining()) }} days</dd>
                </div>
                <div>
                    <dt>Programme</dt>
                    <dd>{{ Candidacy::programmeTypes()[$candidacy->programme_type] ?? $candidacy->programme_type }}</dd>
                </div>
                <div>
                    <dt>Extension used</dt>
                    <dd>{{ $candidacy->extension_months_used }} of {{ Candidacy::MAX_EXTENSION_MONTHS }} months</dd>
                </div>
            </dl>

            @if ($candidacy->status === Candidacy::STATUS_DEFENDED)
                <p class="message-success">Your Research Proposal Defence is recorded as completed on
                   {{ $candidacy->defended_on?->format('j M Y') }}. Reminders have stopped.</p>
            @elseif ($candidacy->status === Candidacy::STATUS_DISMISSED)
                <p class="message-error">This candidacy has been closed following a dismissal for exceeded candidacy.</p>
            @elseif ($candidacy->isOverdue())
                <p class="message-error">
                    Your deadline has passed. CGS may open a dismissal for exceeded candidacy.
                    @if ($candidacy->canAppeal())
                        Filing an extension appeal now is still the fastest route:
                        <a href="{{ route('rpd-appeal.create') }}">file one</a>.
                    @endif
                </p>
            @elseif ($candidacy->monthsRemaining() <= 3)
                <p class="message-warning">
                    Your deadline is under {{ max(1, $candidacy->monthsRemaining()) }}
                    {{ Str::plural('month', max(1, $candidacy->monthsRemaining())) }} away.
                    @if ($candidacy->canAppeal())
                        <a href="{{ route('rpd-appeal.create') }}">File an extension appeal</a> if you need more time.
                    @endif
                </p>
            @endif

            @if ($candidacy->canAppeal())
                <p><a href="{{ route('rpd-appeal.create') }}" class="sdash-action">File an extension appeal</a></p>
            @endif

            <h3>Appeal history</h3>
            @forelse ($appeals as $appeal)
                @php($detail = $details[$appeal->id] ?? null)
                <div class="app-item">
                    <div class="app-item-header">
                        <p><b>Appeal #{{ $appeal->id }}</b></p>
                        <x-core::status-badge :status="$appeal->status" />
                    </div>
                    @if ($detail)
                        <p>Requested {{ $detail->requested_months }} {{ Str::plural('month', $detail->requested_months) }}
                           against a deadline of {{ $detail->deadline_at_filing->format('j M Y') }}.</p>
                        @if ($detail->new_deadline)
                            <p class="tone-good">Granted. Deadline moved to {{ $detail->new_deadline->format('j M Y') }}.</p>
                        @endif
                    @endif
                    <p class="queue-meta"><a href="{{ route('applications.show', $appeal) }}">Track this appeal</a></p>
                </div>
            @empty
                <div class="empty-state">You have not filed an extension appeal.</div>
            @endforelse
        @endif
    </div>
</div>
@endsection
