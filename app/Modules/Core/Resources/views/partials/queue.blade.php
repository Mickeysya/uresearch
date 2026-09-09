{{--
    The shell every approval queue shares.

    A module supplies only the detail lines for its own records, by passing
    the name of a partial as $detailView. That partial receives $application.

    Expects: $stage, $applications, $moduleLabel, $decideRoute, $detailView
--}}
<h2>{{ $moduleLabel }} — {{ $stage->queueTitle() }}</h2>
<p class="queue-meta">
    {{ $applications->count() }} {{ Str::plural('application', $applications->count()) }} awaiting your decision.
</p>

@forelse ($applications as $application)
    <div class="app-item">
        <div class="app-item-header">
            <p><b>Application #{{ $application->id }}</b></p>
            <span style="color: var(--text-grey); font-size: 12.5px;">
                Submitted {{ $application->submitted_at?->diffForHumans() }}
            </span>
        </div>

        <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif</p>

        @include($detailView, ['application' => $application])

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        <x-core::decision-form :application="$application" :route="route($decideRoute, $application)" />
    </div>
@empty
    <div class="empty-state">Nothing is waiting on you right now.</div>
@endforelse
