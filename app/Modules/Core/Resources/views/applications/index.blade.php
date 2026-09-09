@extends('core::layouts.app')

@section('title', 'My Applications')

@section('content')
    <h2>My Applications</h2>

    @forelse ($applications as $application)
        <div class="app-item">
            <div class="app-item-header">
                <p><b>{{ $application->module()->label() }} Application #{{ $application->id }}</b></p>
                <x-core::status-badge :status="$application->status" />
            </div>

            <p>{{ $application->module()->summary($application) }}</p>
            <p style="color: var(--text-grey); font-size: 12.5px;">
                Submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}
            </p>

            <x-core::stepper :application="$application" />

            @if ($rejection = $application->rejection())
                <div class="rejection-note">
                    Rejected at {{ $rejection->stage_label }}.
                    @if ($rejection->remarks)
                        Remarks: {{ $rejection->remarks }}
                    @endif
                </div>
            @endif

            @if ($application->history->isNotEmpty())
                <div class="history-trail">
                    @foreach ($application->history as $entry)
                        <div class="entry">
                            <b>{{ $entry->stage_label }}</b> — {{ $entry->decision }}
                            by {{ $entry->approver->name }}
                            on {{ $entry->created_at->format('j M Y, g:ia') }}
                            @if ($entry->remarks) — "{{ $entry->remarks }}" @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="empty-state">
            You haven't submitted any applications yet.<br>
            Pick one from the sidebar to get started.
        </div>
    @endforelse
@endsection
