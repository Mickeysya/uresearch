@extends('core::layouts.app')

@section('title', 'Re-viva: ' . $stage->queueTitle())

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Re-viva Monitoring: {{ $stage->queueTitle() }}" />

    <div class="card card-wide">
        {{-- total(), not count(): queues are paginated in Core now, and
             count() is how many are on THIS page. --}}
        <p class="queue-meta">
            {{ $applications->total() }} {{ Str::plural('cycle', $applications->total()) }} at this step.
        </p>

        @forelse ($applications as $application)
            @php($detail = $details[$application->id] ?? null)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $application->id }}</b>
                        @if ($detail) Cycle {{ $detail->cycle_number }} @endif
                    </p>
                    <span style="color: var(--text-grey); font-size: 12.5px;">
                        Submitted {{ $application->submitted_at?->diffForHumans() }}
                    </span>
                </div>

                <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif</p>

                @if ($detail)
                    <p>Resubmitted: {{ $detail->resubmission_at->format('j M Y') }}</p>
                    <p>Correction deadline: {{ $detail->correction_deadline->format('j M Y') }}
                        Hardbound deadline: {{ $detail->hardbound_deadline->format('j M Y') }}</p>
                @endif

                @if ($application->documents->isNotEmpty())
                    <ul class="doc-list">
                        @foreach ($application->documents as $doc)
                            <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                        @endforeach
                    </ul>
                @endif

                {{-- Advance only: this stepper has no meaningful "reject" step,
                     so unlike core::decision-form only one button is offered. --}}
                <form method="POST" action="{{ route('reviva.decide', $application) }}">
                    @csrf
                    <input type="hidden" name="decision" value="approve">

                    <label for="remarks-{{ $application->id }}">Remarks <span style="color: var(--text-grey)">(optional)</span></label>
                    <textarea id="remarks-{{ $application->id }}" name="remarks" rows="2"></textarea>

                    <button type="submit">Advance to Next Step</button>
                </form>
            </div>
        @empty
            <div class="empty-state">Nothing at this step right now.</div>
        @endforelse

        {{-- Required, not optional: Core paginates queues, so without this
             every cycle past the first page is simply missing from the page
             with nothing to say so. --}}
        @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Re-viva pages'])
    </div>
</div>
@endsection
