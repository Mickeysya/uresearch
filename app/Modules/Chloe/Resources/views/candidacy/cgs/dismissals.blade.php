@extends('core::layouts.app')

@section('title', 'Dismissal List')

@section('content')
@php use App\Modules\Chloe\Models\CandidacyDismissal; @endphp
@include('chloe::partials.styles')

<div class="card-container-inline">
    <x-core::page-header title="Dismiss Exceeded Study Candidacy"
                         subtitle="Review each candidate, submit to Registry outside this system, then confirm here once Registry has processed it. Confirming updates the student's record and notifies them.">
        <form method="POST" action="{{ route('candidacy.cgs.dismissals.refresh') }}">
            @csrf
            <button type="submit" class="btn-secondary">Refresh list</button>
        </form>
    </x-core::page-header>

    <div class="chloe-stack">
        <div class="card card-wide">
            <h3>Pending review</h3>

            @if ($pending->isEmpty())
                <div class="empty-state">
                    <p>No dismissal candidates are waiting for review.</p>
                    <p class="queue-meta">Refresh the list to pick up candidacies that have expired since it was last built.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Reason</th><th>Candidacy expiry</th><th>Generated</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($pending as $dismissal)
                                <tr>
                                    <td>
                                        {{ $dismissal->student->name ?? '—' }}
                                        @if ($dismissal->student?->matric_no)
                                            <span class="queue-meta">{{ $dismissal->student->matric_no }}</span>
                                        @endif
                                    </td>
                                    <td>{{ CandidacyDismissal::reasonLabel($dismissal->reason) }}</td>
                                    <td class="rpd-num">{{ $dismissal->candidacy->candidacy_expiry_date->format('j M Y') }}</td>
                                    <td class="rpd-num">{{ $dismissal->generated_at->format('j M Y') }}</td>
                                    <td class="rpd-actions">
                                        <form method="POST" action="{{ route('candidacy.cgs.dismissals.confirm', $dismissal) }}" class="rpd-inline-form">
                                            @csrf
                                            <button type="submit">Confirm Registry processed</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card card-wide">
            <h3>Recently confirmed</h3>

            @if ($confirmed->isEmpty())
                <div class="empty-state">Nothing has been confirmed yet.</div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Reason</th><th>Confirmed by</th><th>Confirmed on</th></tr></thead>
                        <tbody>
                            @foreach ($confirmed as $dismissal)
                                <tr>
                                    <td>{{ $dismissal->student->name ?? '—' }}</td>
                                    <td>{{ CandidacyDismissal::reasonLabel($dismissal->reason) }}</td>
                                    <td>{{ $dismissal->confirmedBy->name ?? '—' }}</td>
                                    <td class="rpd-num">{{ $dismissal->confirmed_at?->format('j M Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
