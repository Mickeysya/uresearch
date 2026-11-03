@extends('core::layouts.app')

@section('title', 'Issued Appointment Letters')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Issued Appointment Letters"
        subtitle="Every supervisor appointment letter generated, Valid or Revoked.">
        <a href="{{ route('supervision.queue') }}" class="btn-secondary">Back to Queue</a>
    </x-core::page-header>

    <div class="card card-wide">
        @if ($details->isEmpty())
            <p class="queue-meta">No appointment letters have been issued yet.</p>
        @else
            @foreach ($details as $detail)
                <div style="{{ ! $loop->first ? 'margin-top: var(--space-5); padding-top: var(--space-5); border-top: 1px solid var(--border-subtle);' : '' }}">
                    <p style="margin: 0;">
                        <a href="{{ route('applications.show', $detail->application_id) }}">
                            Application #{{ $detail->application_id }}
                        </a>,
                        {{ $detail->application->student?->name ?? 'Unknown student' }},
                        {{ $detail->requestedSupervisor->name }}
                        ({{ $detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO ? 'Co-Supervisor' : 'Main Supervisor' }})
                    </p>

                    @include('nureen::supervision._letter_status', [
                        'detail' => $detail,
                        'application' => $detail->application,
                    ])
                </div>
            @endforeach

            @include('core::partials.pagination', ['paginator' => $details, 'label' => 'Issued appointment letters pages'])
        @endif
    </div>
</div>
@endsection
