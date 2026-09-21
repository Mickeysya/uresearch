@extends('core::layouts.app')

@section('title', 'My Candidacy')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="My Study Candidacy" />

    <div class="card card-wide">
        @if (! $candidacy)
            <div class="empty-state">No candidacy record found for your account yet. Contact CGS if you believe this is an error.</div>
        @else
            <p>
                Status: <span class="status-badge {{ $candidacy->status === 'active' ? 'approved' : ($candidacy->status === 'dismissed' ? 'rejected' : 'draft') }}">{{ ucfirst($candidacy->status) }}</span>
            </p>
            <p>Programme started: {{ $candidacy->programme_start_date->format('j M Y') }}</p>
            <p>Candidacy expiry date: <b>{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</b></p>

            @if ($candidacy->status === 'active')
                @php($days = $candidacy->daysUntilExpiry())
                <p style="color: var(--text-grey); font-size: 13px;">
                    @if ($days >= 0)
                        {{ $days }} day(s) remaining.
                    @else
                        Expired {{ abs($days) }} day(s) ago.
                    @endif
                </p>
            @endif

            <p>Appeal allowance used: {{ $candidacy->cumulative_extension_months }} / {{ \App\Modules\Chloe\Models\StudyCandidacy::MAX_APPEAL_MONTHS }} months</p>

            @if ($candidacy->canAppeal() && ! $candidacy->hasOpenAppeal())
                <a href="{{ route('candidacy-appeal.create') }}"><button type="submit">Submit an Appeal</button></a>
            @elseif ($candidacy->hasOpenAppeal())
                <p style="color: var(--text-grey); font-size: 13px;">You have an appeal in progress; see below.</p>
            @elseif ($candidacy->last_rejection_at)
                <p style="color: var(--text-grey); font-size: 13px;">A previous appeal was rejected; no further appeals can be filed.</p>
            @elseif ($candidacy->remainingAppealMonths() <= 0)
                <p style="color: var(--text-grey); font-size: 13px;">You have used your full 12-month appeal allowance.</p>
            @endif
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h3>My Appeals</h3>
        <div class="card-divider"></div>

        @if ($appeals->isEmpty())
            <div class="empty-state">You haven't submitted a Study Candidacy Appeal yet.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>#</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                    @foreach ($appeals as $appeal)
                        <tr>
                            <td>Appeal #{{ $appeal->id }}</td>
                            <td><x-core::status-badge :status="$appeal->status" /></td>
                            <td>{{ $appeal->submitted_at?->format('j M Y') }}</td>
                            <td><a href="{{ route('candidacy-appeal.show', $appeal) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h3>My Reminder History</h3>
        <div class="card-divider"></div>

        @if ($reminders->isEmpty())
            <div class="empty-state">No reminders sent yet.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>#</th><th>Sent</th></tr></thead>
                <tbody>
                    @foreach ($reminders as $reminder)
                        <tr>
                            <td>Reminder {{ $reminder->reminder_number }}</td>
                            <td>{{ $reminder->sent_at->format('j M Y, g:ia') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
