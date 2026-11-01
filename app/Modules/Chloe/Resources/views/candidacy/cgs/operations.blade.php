@extends('core::layouts.app')

@section('title', 'Candidacy Management')

@include('chloe::candidacy.wca._assets')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Candidacy Management">
        <a href="{{ route('candidacy.cgs.dismissals') }}"><button type="button">Review the dismissal list</button></a>
    </x-core::page-header>

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

    <div class="card card-wide">
        <h3>Pending Appeals</h3>
        <div class="card-divider"></div>

        @if ($pendingAppeals->isEmpty())
            <div class="empty-state">No appeals currently pending.</div>
        @else
            <div class="wca-table-wrap"><table class="recent-activity-table">
                <thead><tr><th>Appeal No.</th><th>Student</th><th>Current step</th><th>Submitted</th></tr></thead>
                <tbody>
                    @foreach ($pendingAppeals as $appeal)
                        <tr>
                            <td><a class="wca-link" href="{{ route('candidacy.appeals.show', $appeal) }}">{{ $appeal->displayNo() }}</a></td>
                            <td>{{ $appeal->identity()?->name ?? '—' }}</td>
                            <td>{{ \App\Modules\Chloe\Support\CandidacyAppealPresenter::currentStep($appeal) }}</td>
                            <td>{{ $appeal->submitted_at?->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h3>Students Near Candidacy Expiry</h3>
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
@include('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])
@endsection
