@php
    $detail = $details[$application->id] ?? null;
    $check = $appointmentChecks[$application->id] ?? null;
@endphp

@if ($detail)
    <p>Appointment Type: <b>{{ $detail->appointment_type }}</b></p>
    <p>Supervisor: {{ $detail->supervisor?->name ?? 'Not recorded' }}</p>
    <p>Period: {{ $detail->period_start->format('j M Y') }} – {{ $detail->period_end->format('j M Y') }}</p>

    @foreach ($detail->typeFields() as $key => $field)
        @continue(empty($detail->{$key}))
        <p>{{ $field['label'] }}: {{ $detail->{$key} }}</p>
    @endforeach

    @if ($detail->purpose)
        <p>Purpose: {{ $detail->purpose }}</p>
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
