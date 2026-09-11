@extends('core::layouts.app')

@section('title', $title)

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>{{ $title }}</h2>
        <div class="card-divider"></div>

        <div class="empty-state">
            <p style="margin: 0 0 6px; font-weight: 600; color: var(--text-dark);">This is the {{ $title }} page.</p>
            <p style="margin: 0;">{{ $description }}</p>
        </div>
    </div>
</div>
@endsection
