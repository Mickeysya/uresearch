{{-- .nureen-row: the wrapper components/decision-bar keys on. --}}
<div class="nureen-row">
@include('nureen::charter._queue_clock')

@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>
        Requested Supervisor: <b>{{ $detail->requestedSupervisor->name }}</b>,
        {{ $detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO ? 'Co-Supervisor' : 'Main Supervisor' }}
        ({{ $detail->appointment_type ? ucfirst($detail->appointment_type) : 'Not recorded' }})
    </p>
    <p>Justification: {{ $detail->justification }}</p>

    {{--
        System checks (Postgraduate Handbook 2025). A GA changing Main
        Supervisor needs PGFAC approval first, with the supervisor's memo --
        SupervisionController::store() requires the upload, so this recounts
        what is actually attached. Requests submitted before the check
        existed have no snapshot and say so.
    --}}
    <div class="sv-checks">
        <p class="sv-checks-title">System checks</p>
        <ul>
            @if ($detail->is_change_of_supervisor === null)
                <li class="sv-unknown">? Change of supervisor and GA status <span class="queue-meta">Submitted before these were recorded.</span></li>
            @else
                <li class="sv-info">• {{ $detail->is_change_of_supervisor ? 'Change of Main Supervisor' : ($detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO ? 'Co-Supervisor appointment' : 'First Main Supervisor') }}
                    <span class="queue-meta">
                        @if ($detail->is_ga === null)
                            GA status: not recorded.
                        @else
                            GA status: {{ $detail->is_ga ? 'Graduate Assistant' : 'not a GA' }} ({{ $detail->ga_status_source === 'record' ? 'from the GA appointment record' : 'declared by the student' }}).
                        @endif
                    </span>
                </li>
                @if ($detail->needsPgfacMemo())
                    @php($memoCount = $application->documents->where('doc_type', config('nureen.supervision.pgfac_memo.label'))->count())
                    <li class="{{ $memoCount > 0 ? 'sv-pass' : 'sv-fail' }}">
                        {{ $memoCount > 0 ? '✓' : '✗' }} {{ config('nureen.supervision.pgfac_memo.label') }}
                        {{ $memoCount > 0 ? '('.$memoCount.' file'.($memoCount === 1 ? '' : 's').')' : '(missing)' }}
                        <span class="queue-meta">A GA changing supervisor needs PGFAC approval first, with the supervisor's memo.</span>
                    </li>
                @else
                    <li class="sv-pass">✓ PGFAC approval memo <span class="queue-meta">Not needed: only for a GA changing Main Supervisor.</span></li>
                @endif
            @endif
            @if ($detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO)
                <li class="sv-info">• Co-Supervisor approver: {{ $detail->co_supervisor_approver_snapshot === 'dean_pgr' ? 'Dean of PGR' : 'Chair of Department' }}
                    <span class="queue-meta">nureen.supervision.co_supervisor_approver at submission.</span></li>
            @endif
        </ul>
    </div>
    @once
        <style>
            .sv-checks { margin: var(--space-3) 0; padding-top: var(--space-3); border-top: 1px solid var(--border-subtle); }
            .sv-checks-title { margin: 0 0 var(--space-2); font-weight: var(--weight-semi); color: var(--text-dark); }
            .sv-checks ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-1); }
            .sv-checks .queue-meta { display: block; margin: 0 0 0 1.3em; }
            .sv-pass { color: var(--success-fg); } .sv-fail { color: var(--danger-fg); } .sv-unknown, .sv-info { color: var(--text-dark); }
        </style>
    @endonce

    @if ($detail->letter_reference)
        @include('nureen::supervision._letter_status', ['detail' => $detail, 'application' => $application])
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif

{{-- System checks (above) → Documents → Remarks → actions. The draft
     letter is a Documents row now, not a separate "Preview letter" box. --}}
@php($panel = \App\Modules\Nureen\Support\ApproverPanel::supervision($application, auth()->user()))
<x-nureen::documents :items="$panel['documents']" />
<x-nureen::decision-bar :application="$application" :route="route('supervision.decide', $application)"
    :approve-label="$panel['approveLabel']" :hint="$panel['hint']" />
</div>
