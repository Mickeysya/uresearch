@extends('core::layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
    {{--
        Administrator dashboard — single screen, by requirement.

        No welcome banner: the design opens straight on the page title and the
        five figures, unlike the student and CGS dashboards.

        Four grid rows — heading, figures, then two panel rows. The page never
        scrolls on a desktop viewport; panels scroll internally instead.
    --}}
    <div class="sdash sdash-admin">
        <header class="adm-header">
            <div>
                <h2>Admin Dashboard</h2>
                <p>Monitor system overview, manage users and oversee postgraduate administration.</p>
            </div>

            <a href="{{ route('admin.reports.index') }}" class="adm-generate">
                <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'download'])</span>
                Generate Report
            </a>
        </header>

        @include('core::dashboard.partials.admin-stat-cards')

        <div class="cgs-row adm-row-top">
            @include('core::dashboard.partials.admin-activities')
            @include('core::dashboard.partials.admin-system')
        </div>

        <div class="cgs-row adm-row-bottom">
            @include('core::dashboard.partials.admin-status-chart')
            @include('core::dashboard.partials.admin-quick-actions')
        </div>
    </div>
@endsection
