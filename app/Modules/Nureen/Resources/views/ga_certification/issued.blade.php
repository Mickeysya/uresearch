@extends('core::layouts.app')

@section('title', 'Issued Certificates')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Issued Certificates"
        subtitle="Every GA/GRA certification letter generated, Valid or Revoked.">
        <a href="{{ route('ga-certification.queue') }}" class="btn-secondary">Back to Queue</a>
    </x-core::page-header>

    <div class="card card-wide">
        @if ($details->isEmpty())
            <p class="queue-meta">No certificates have been generated yet.</p>
        @else
            @foreach ($details as $detail)
                <div style="{{ ! $loop->first ? 'margin-top: var(--space-5); padding-top: var(--space-5); border-top: 1px solid var(--border-subtle);' : '' }}">
                    <p style="margin: 0;">
                        <a href="{{ route('applications.show', $detail->application_id) }}">
                            Application #{{ $detail->application_id }}
                        </a>,
                        {{ $detail->application->student?->name ?? 'Unknown student' }}
                        ({{ $detail->appointment_type }})
                    </p>

                    @include('nureen::ga_certification._certificate_status', [
                        'detail' => $detail,
                        'application' => $detail->application,
                    ])
                </div>
            @endforeach

            @include('core::partials.pagination', ['paginator' => $details, 'label' => 'Issued certificates pages'])
        @endif
    </div>
</div>
@endsection
