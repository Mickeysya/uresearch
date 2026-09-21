@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal — ' . $stage->queueTitle())

{{--
    Not @include('core::partials.queue', ...) — that shared partial's
    decision-form call doesn't pass allowReturn, and this chain needs a
    Return button the others don't. Same markup/classes as the shared
    partial otherwise, so it looks identical. See
    app/Modules/Chloe/README.md.
--}}
@section('content')
<h2>Study Candidacy Appeal — {{ $stage->queueTitle() }}</h2>
<p class="queue-meta">
    {{ $applications->count() }} {{ Str::plural('application', $applications->count()) }} awaiting your decision.
</p>

@forelse ($applications as $application)
    @php($detail = $details[$application->id] ?? null)
    <div class="app-item">
        <div class="app-item-header">
            <p><b>Appeal #{{ $application->id }}</b></p>
            <span style="color: var(--text-grey); font-size: 12.5px;">
                Submitted {{ $application->submitted_at?->diffForHumans() }}
            </span>
        </div>

        <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif</p>

        @if ($detail)
            @include('chloe::candidacy.appeal._sections', ['detail' => $detail])
        @endif

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        <x-core::decision-form :application="$application" :route="route('candidacy-appeal.decide', $application)" :allow-return="true" />
    </div>
@empty
    <div class="empty-state">Nothing is waiting on you right now.</div>
@endforelse
@endsection
