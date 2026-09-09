@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="welcome-banner">
        <h2>Welcome, {{ auth()->user()->name }}</h2>
        <p>{{ auth()->user()->roleLabel() }}@if (auth()->user()->programme) &middot; {{ auth()->user()->programme }}@endif</p>
    </div>

    <div class="stat-cards-row">
        <div class="stat-card accent-gold">
            <div class="stat-number">{{ $open }}</div>
            <div class="stat-label">In progress</div>
        </div>
        <div class="stat-card accent-green">
            <div class="stat-number">{{ $approved }}</div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card accent-red">
            <div class="stat-number">{{ $rejected }}</div>
            <div class="stat-label">Not approved</div>
        </div>
    </div>

    <h3 style="color: var(--navy); font-size: 15px;">Recent applications</h3>

    @forelse ($recent as $application)
        <div class="app-item">
            <div class="app-item-header">
                <p><b>{{ $application->module()->label() }} #{{ $application->id }}</b></p>
                <x-core::status-badge :status="$application->status" />
            </div>
            <p>{{ $application->module()->summary($application) }}</p>
            @if ($stage = $application->currentStage())
                <p style="color: var(--text-grey); font-size: 12.5px;">Now with: {{ $stage->label }}</p>
            @endif
        </div>
    @empty
        <div class="empty-state">
            Nothing submitted yet. Choose an application type from the sidebar to begin.
        </div>
    @endforelse
@endsection
