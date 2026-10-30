@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>
        Requested Supervisor: <b>{{ $detail->requestedSupervisor->name }}</b>,
        {{ $detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO ? 'Co-Supervisor' : 'Main Supervisor' }}
        ({{ $detail->appointment_type ? ucfirst($detail->appointment_type) : 'Not recorded' }})
    </p>
    <p>Justification: {{ $detail->justification }}</p>

    @if ($detail->letter_reference)
        @include('nureen::supervision._letter_status', ['detail' => $detail, 'application' => $application])
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
