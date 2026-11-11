@extends('core::layouts.app')

@section('title', 'GRA Application: ' . $stage->queueTitle())

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GRA Application: {{ $stage->queueTitle() }}"
        subtitle="{{ $applications->count() }} {{ Str::plural('application', $applications->count()) }} awaiting your decision." />

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

        @php($queueDocuments = $application->documents->whereIn('doc_type', array_values($documentTypes)))
        @if ($queueDocuments->isNotEmpty())
            <ul class="doc-list">
                @foreach ($queueDocuments as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        @if ($stage->key === 'admin_verify')
            {{-- Admin never rejects here — see GraApplicationController::decide(). The only
                 way to say "this is wrong" is the Request Correction form above. --}}
            @php($missing = ($missingDocuments ?? collect())[$application->id] ?? collect())
            @if ($missing->isNotEmpty())
                <p class="queue-meta" style="color: var(--danger-fg);">
                    Still missing: {{ $missing->implode(', ') }}. Can't verify until every required document is uploaded.
                </p>
                <button type="button" disabled title="Still missing: {{ $missing->implode(', ') }}">Verify &amp; Advance to CGS</button>
            @else
                <form method="POST" action="{{ route('gra-application.decide', $application) }}">
                    @csrf
                    <input type="hidden" name="decision" value="approve">
                    <button type="submit" data-confirm="Verify and advance this application to CGS?">Verify &amp; Advance to CGS</button>
                </form>
            @endif
        @else
            @include('haziq::partials.decision_form', ['application' => $application, 'route' => route('gra-application.decide', $application)])
        @endif
    </div>
@empty
    <div class="empty-state">Nothing is waiting on you right now.</div>
@endforelse
</div>

@include('haziq::partials.confirm_scripts')
@endsection
