@extends('core::layouts.app')

@section('title', 'Dismissal List')

@section('content')
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2 style="color: var(--navy); margin:0;">Dismiss Exceeded Study Candidacy</h2>
    <form method="POST" action="{{ route('candidacy.cgs.dismissals.refresh') }}">
        @csrf
        <button type="submit">Refresh List</button>
    </form>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Pending Review</h2>
        <p style="color: var(--text-grey); font-size: 13px;">
            Review each candidate, then submit to Registry outside this system. Once Registry has processed a
            dismissal, confirm it here — that updates the student's record and sends their notification.
        </p>
        <div class="card-divider"></div>

        @if ($pending->isEmpty())
            <div class="empty-state">No dismissal candidates awaiting review.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>Student</th><th>Reason</th><th>Candidacy Expiry</th><th>Generated</th><th>Action</th></tr></thead>
                <tbody>
                    @foreach ($pending as $dismissal)
                        <tr>
                            <td>{{ $dismissal->student->name ?? '—' }}@if ($dismissal->student?->matric_no) ({{ $dismissal->student->matric_no }})@endif</td>
                            <td>{{ \App\Modules\Chloe\Models\CandidacyDismissal::reasonLabel($dismissal->reason) }}</td>
                            <td>{{ $dismissal->candidacy->candidacy_expiry_date->format('j M Y') }}</td>
                            <td>{{ $dismissal->generated_at->format('j M Y') }}</td>
                            <td>
                                <form method="POST" action="{{ route('candidacy.cgs.dismissals.confirm', $dismissal) }}">
                                    @csrf
                                    <button type="submit">Confirm Registry Processed</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Recently Confirmed</h2>
        <div class="card-divider"></div>

        @if ($confirmed->isEmpty())
            <div class="empty-state">Nothing confirmed yet.</div>
        @else
            <table class="recent-activity-table">
                <thead><tr><th>Student</th><th>Reason</th><th>Confirmed By</th><th>Confirmed On</th></tr></thead>
                <tbody>
                    @foreach ($confirmed as $dismissal)
                        <tr>
                            <td>{{ $dismissal->student->name ?? '—' }}</td>
                            <td>{{ \App\Modules\Chloe\Models\CandidacyDismissal::reasonLabel($dismissal->reason) }}</td>
                            <td>{{ $dismissal->confirmedBy->name ?? '—' }}</td>
                            <td>{{ $dismissal->confirmed_at?->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
