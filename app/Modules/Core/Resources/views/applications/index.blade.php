@extends('core::layouts.app')

@section('title', 'My Applications')

@section('content')
    <x-core::page-header title="My Applications"
                         subtitle="Every application you have filed, and where each one has reached." />

    @forelse ($applications as $application)
        <div class="app-item">
            <div class="app-item-header">
                <p><b>{{ $application->module()->label() }} Application #{{ $application->id }}</b></p>
                <div style="display:flex; align-items:center; gap:12px;">
                    <x-core::status-badge :status="$application->status" />
                    <a href="{{ route('applications.show', $application) }}">View</a>
                </div>
            </div>

            @php($module = $application->module())
            @if ($module instanceof \App\Modules\Core\Contracts\SuppliesApplicationDetail)
                @include($module->detailView(), ['application' => $application])
            @else
                <p>{{ $module->summary($application) }}</p>
            @endif
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
                            <b>{{ $entry->stage_label }}</b>: {{ $entry->decision }}
                            by {{ $entry->approver->name }}
                            on {{ $entry->created_at->format('j M Y, g:ia') }}
                            @if ($entry->remarks) "{{ $entry->remarks }}" @endif
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
