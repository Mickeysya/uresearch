@extends('core::layouts.app')

@section('title', 'Candidacy Management')

@section('content')
<h2 style="color: var(--navy);">Candidacy Management</h2>

<div class="stat-cards-row">
    <div class="stat-card accent-blue">
        <div class="stat-number">{{ $pendingAppeals->count() }}</div>
        <div class="stat-label">Pending Appeals</div>
    </div>
    @foreach ($appealStatusSummary as $label => $count)
        <div class="stat-card accent-gold">
            <div class="stat-number">{{ $count }}</div>
            <div class="stat-label">{{ $label }}</div>
        </div>
    @endforeach
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Pending Appeals</h2>
        <div class="card-divider"></div>

        @if ($pendingAppeals->isEmpty())
            <div class="empty-state">No appeals currently pending.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>#</th><th>Student</th><th>Stage</th><th>Submitted</th></tr></thead>
                <tbody>
                    @foreach ($pendingAppeals as $application)
                        <tr>
                            <td>{{ $application->reference() }}</td>
                            <td>{{ $application->student->name ?? '—' }}</td>
                            <td>{{ $application->currentStage()?->label ?? '—' }}</td>
                            <td>{{ $application->submitted_at?->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Students Near Candidacy Expiry</h2>
        <p style="color: var(--text-grey); font-size: 13px;">Active candidacies expiring within 3 months.</p>
        <div class="card-divider"></div>

        @if ($nearExpiry->isEmpty())
            <div class="empty-state">No students currently near expiry.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>Student</th><th>Expiry Date</th><th>Days Remaining</th></tr></thead>
                <tbody>
                    @foreach ($nearExpiry as $candidacy)
                        <tr>
                            <td>{{ $candidacy->student->name ?? '—' }}</td>
                            <td>{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</td>
                            <td>{{ $candidacy->daysUntilExpiry() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<p><a href="{{ route('candidacy.cgs.dismissals') }}">Review the dismissal list →</a></p>
@endsection
