@extends('core::layouts.app')

@section('title', 'GRA Application — ' . $stage->queueTitle())

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<h2>GRA Application — {{ $stage->queueTitle() }}</h2>
<p class="queue-meta">{{ $applications->count() }} {{ Str::plural('application', $applications->count()) }} awaiting your decision.</p>

@forelse ($applications as $application)
    <div class="app-item">
        <div class="app-item-header">
            <p><b>Application #{{ $application->id }}</b></p>
            <span style="color: var(--text-grey); font-size: 12.5px;">Submitted {{ $application->submitted_at?->diffForHumans() }}</span>
        </div>

        <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif
            &middot; <a href="{{ route('gra-application.show', $application) }}">View full application</a>
        </p>

        @include('haziq::gra_application._detail', ['application' => $application])

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        @if ($stage->key === 'admin_verify')
            {{-- Admin never rejects here — see GraApplicationController::decide(). The only
                 way to say "this is wrong" is the Request Correction form above. --}}
            <form method="POST" action="{{ route('gra-application.decide', $application) }}">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <button type="submit" data-confirm="Verify and advance this application to CGS?">Verify &amp; Advance to CGS</button>
            </form>
        @else
            @include('haziq::partials.decision_form', ['application' => $application, 'route' => route('gra-application.decide', $application)])
        @endif
    </div>
@empty
    <div class="empty-state">Nothing is waiting on you right now.</div>
@endforelse

@include('haziq::partials.confirm_scripts')
@endsection
