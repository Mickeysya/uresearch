@include('nureen::charter._queue_clock')

@php
    $detail = $details[$application->id] ?? null;
    $check = $appointmentChecks[$application->id] ?? null;
    $student = $application->student;
@endphp

@if ($detail)
    <p>Appointment Type: <b>{{ $detail->appointment_type }}</b></p>
    <p>Department / Programme: {{ $student?->department ?? '—' }} · {{ $student?->programme ?? '—' }}</p>
    @if ($detail->studySummary())
        <p>Study: {{ $detail->studySummary() }}</p>
    @endif
    {{-- Full number for CGS staff only; everyone else sees the last 4. --}}
    @if ($detail->identity_number)
        <p>MyKad/Passport: {{ $detail->identityFor(auth()->user()) }}</p>
    @endif
    @if ($detail->personal_email)
        <p>Personal email: {{ $detail->personal_email }}</p>
    @endif
    <p>Supervisor: {{ $detail->supervisor?->name ?? 'Not recorded' }}@if ($detail->supervisor?->email) ({{ $detail->supervisor->email }})@endif</p>
    @if ($detail->period_start && $detail->period_end)
        <p>Period: {{ $detail->period_start->format('j M Y') }} – {{ $detail->period_end->format('j M Y') }}</p>
    @endif

    @foreach ($detail->typeFields() as $key => $field)
        @continue(empty($detail->{$key}))
        <p>{{ $field['label'] }}: {{ $detail->{$key} }}</p>
    @endforeach

    @if ($detail->purpose)
        <p>Purpose: {{ $detail->purpose }}</p>
    @endif

    {{-- Postgraduate Handbook 2025: GA/GRA are for full-time research
         students. A warning, not a block -- GaCertificationDetail::eligibilityCheck(). --}}
    @php
        $eligibility = $detail->eligibilityCheck();
    @endphp
    <div class="cert-checks">
        <p class="cert-checks-title">System checks</p>
        <p class="cert-check-{{ $eligibility['status'] }}">
            {{ ['pass' => '✓', 'fail' => '✗', 'unknown' => '?'][$eligibility['status']] }} Full-time research student
            <span class="queue-meta">{{ $eligibility['reason'] }}</span>
        </p>
    </div>
    @once
        <style>
            .cert-checks { margin: var(--space-3) 0; padding-top: var(--space-3); border-top: 1px solid var(--border-subtle); }
            .cert-checks-title { margin: 0 0 var(--space-2); font-weight: var(--weight-semi); color: var(--text-dark); }
            .cert-checks .queue-meta { display: block; margin-left: 1.3em; }
            .cert-check-pass { color: var(--success-fg); } .cert-check-fail { color: var(--danger-fg); } .cert-check-unknown { color: var(--text-grey); }
        </style>
    @endonce

    @if ($detail->declaration_accepted_at)
        <p class="queue-meta">Declaration accepted {{ $detail->declaration_accepted_at->format('j M Y, g:i a') }}</p>
    @endif

    {{-- The Senior Director sees the exact letter, in their own name,
         before approving -- approving is what issues it. --}}
    @if ($application->current_stage === 'senior_director')
        @include('nureen::letters._preview_styles')
        <div class="nureen-letter-actions">
            <a href="{{ route('ga-certification.letter.preview', $application) }}" target="_blank" rel="noopener" class="btn-secondary">Preview letter</a>
            <p class="hint">A DRAFT of the letter that approving will issue, with your name as signatory. Nothing is issued or sent by previewing.</p>
        </div>
    @endif

    {{--
        "Before CGS staff review, the system automatically checks the
        student's GA/GRA appointment" -- NOT part of CGS's confirmed
        process; see config('nureen.ga_certification.appointment_check_enabled').
        $check is only ever present (non-null) when that is turned on --
        CertificationController::queue() does not even compute it otherwise.
        Informational only: it does not block Approve/Reject below.
    --}}
    @if ($check)
        @php
            $tone = match ($check['status']) {
                \App\Modules\Nureen\Support\AppointmentVerifier::VERIFIED => ['label' => 'Verified', 'color' => 'var(--success-fg)', 'bg' => 'var(--success-bg)'],
                \App\Modules\Nureen\Support\AppointmentVerifier::MISMATCH => ['label' => 'Mismatch', 'color' => 'var(--warning-fg)', 'bg' => 'var(--warning-bg)'],
                default => ['label' => 'Not Found', 'color' => 'var(--danger-fg)', 'bg' => 'var(--danger-bg)'],
            };
        @endphp
        <p style="margin-top: var(--space-2);">
            Appointment check:
            <span style="display: inline-block; padding: 2px 10px; border-radius: var(--radius-full);
                         font-size: var(--text-sm); font-weight: var(--weight-semi);
                         background: {{ $tone['bg'] }}; color: {{ $tone['color'] }};">
                {{ $tone['label'] }}
            </span>
            @if ($check['status'] === \App\Modules\Nureen\Support\AppointmentVerifier::MISMATCH && $check['record'])
                <span class="queue-meta">
                    (on file: {{ $check['record']->appointment_type }},
                    {{ $check['record']->period_start->format('j M Y') }} – {{ $check['record']->period_end->format('j M Y') }})
                </span>
            @endif
        </p>
    @endif

    @if ($detail->reference_number)
        @include('nureen::ga_certification._certificate_status', ['detail' => $detail, 'application' => $application])
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
