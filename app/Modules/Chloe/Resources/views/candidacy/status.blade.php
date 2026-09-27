@extends('core::layouts.app')

@section('title', 'My Candidacy')

@section('content')
@php use App\Modules\Chloe\Models\StudyCandidacy; @endphp
@include('chloe::partials.styles')

<div class="card-container-inline">
    <x-core::page-header title="My Study Candidacy"
                         subtitle="How long your candidacy has left, and any appeal you have filed to extend it.">
        @if ($candidacy && $candidacy->canAppeal() && ! $candidacy->hasOpenAppeal())
            <a href="{{ route('candidacy-appeal.create') }}" class="btn">Submit an appeal</a>
        @endif
    </x-core::page-header>

    <div class="chloe-stack">
        <div class="card card-wide">
            @if (! $candidacy)
                <div class="empty-state">
                    <p>No candidacy is on record for you yet.</p>
                    <p class="queue-meta">CGS registers your candidacy. Contact them if you believe this is an error.</p>
                </div>
            @else
                @php
                    $days = $candidacy->daysUntilExpiry();
                    $tone = match (true) {
                        $candidacy->status !== StudyCandidacy::STATUS_ACTIVE => 'info',
                        $days < 0 => 'critical',
                        $days <= 90 => 'warn',
                        default => 'good',
                    };
                    $badge = match ($candidacy->status) {
                        StudyCandidacy::STATUS_ACTIVE => 'status-active',
                        StudyCandidacy::STATUS_DISMISSED => 'status-dismissed',
                        StudyCandidacy::STATUS_COMPLETED => 'approved',
                        default => 'draft',
                    };
                @endphp

                <dl class="rpd-facts">
                    <div>
                        <dt>Status</dt>
                        <dd><span class="status-badge {{ $badge }}">{{ StudyCandidacy::statuses()[$candidacy->status] ?? ucfirst($candidacy->status) }}</span></dd>
                    </div>
                    <div>
                        <dt>Candidacy expires</dt>
                        <dd class="tone-{{ $tone }}">{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</dd>
                    </div>
                    @if ($candidacy->status === StudyCandidacy::STATUS_ACTIVE)
                        <div>
                            <dt>{{ $days < 0 ? 'Expired' : 'Time remaining' }}</dt>
                            <dd class="tone-{{ $tone }}">{{ abs($days) }} {{ Str::plural('day', abs($days)) }}{{ $days < 0 ? ' ago' : '' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>Extension used</dt>
                        <dd>{{ $candidacy->cumulative_extension_months }} of {{ StudyCandidacy::MAX_APPEAL_MONTHS }} months</dd>
                    </div>
                    <div>
                        <dt>Programme started</dt>
                        <dd>{{ $candidacy->programme_start_date->format('j M Y') }}</dd>
                    </div>
                </dl>

                @if ($candidacy->status === StudyCandidacy::STATUS_DISMISSED)
                    <p class="message-error">This candidacy has been dismissed for exceeding its expiry date.</p>
                @elseif ($candidacy->hasOpenAppeal())
                    <p class="message-info">You have an appeal in progress. Its status is under My Appeals below.</p>
                @elseif ($candidacy->last_rejection_at)
                    <p class="message-error">A previous appeal was rejected, so no further appeals can be filed.</p>
                @elseif ($candidacy->remainingAppealMonths() <= 0)
                    <p class="message-info">You have used your full {{ StudyCandidacy::MAX_APPEAL_MONTHS }}-month extension allowance.</p>
                @elseif ($candidacy->status === StudyCandidacy::STATUS_ACTIVE && $days < 0)
                    <p class="message-error">Your candidacy has expired. CGS may dismiss it; filing an appeal now is the fastest way to keep it.</p>
                @elseif ($candidacy->status === StudyCandidacy::STATUS_ACTIVE && $days <= 90)
                    <p class="message-warning">Your candidacy expires in under three months. Submit an appeal if you need more time.</p>
                @endif
            @endif
        </div>

        @if ($candidacy)
            <div class="card card-wide">
                <h3>My Appeals</h3>

                @if ($appeals->isEmpty())
                    <div class="empty-state">
                        <p>You have not submitted a study candidacy appeal.</p>
                        @if ($candidacy->canAppeal() && ! $candidacy->hasOpenAppeal())
                            <a href="{{ route('candidacy-appeal.create') }}" class="btn-secondary">Submit an appeal</a>
                        @endif
                    </div>
                @else
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Appeal</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($appeals as $appeal)
                                    <tr>
                                        <td>#{{ $appeal->id }}</td>
                                        <td><x-core::status-badge :status="$appeal->status" /></td>
                                        <td>{{ $appeal->submitted_at?->format('j M Y') ?? '—' }}</td>
                                        <td><a href="{{ route('candidacy-appeal.show', $appeal) }}">View</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card card-wide">
                <h3>Reminders sent to you</h3>

                @if ($reminders->isEmpty())
                    <div class="empty-state">
                        <p>No reminders sent yet.</p>
                        <p class="queue-meta">CGS emails you as your expiry date gets close.</p>
                    </div>
                @else
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Reminder</th><th>Sent</th></tr></thead>
                            <tbody>
                                @foreach ($reminders as $reminder)
                                    <tr>
                                        <td>Reminder {{ $reminder->reminder_number }}</td>
                                        <td>{{ $reminder->sent_at->format('j M Y, g:ia') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
