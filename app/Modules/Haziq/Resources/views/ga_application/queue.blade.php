@extends('core::layouts.app')

@section('title', 'GA Application: ' . $stage->queueTitle())

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA Application: {{ $stage->queueTitle() }}"
        subtitle="{{ $applications->count() }} {{ Str::plural('application', $applications->count()) }} awaiting your decision." />

@forelse ($applications as $application)
    @php($detail = $details[$application->id] ?? null)
    <div class="app-item">
        <div class="app-item-header">
            <p><b>Application #{{ $application->id }}</b></p>
            <span style="color: var(--text-grey); font-size: 12.5px;">Submitted {{ $application->submitted_at?->diffForHumans() }}</span>
        </div>

        <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif</p>

        @if ($detail)
            <p>{{ $detail->department_unit ?? 'General' }} &middot; {{ $detail->purpose }}</p>
            <p>Service: {{ $detail->service_start_date->format('j M Y') }} to {{ $detail->service_end_date->format('j M Y') }} &middot;
               RM {{ number_format($detail->proposed_allowance, 2) }}</p>
        @endif

        @php($queueDocuments = $application->documents->whereIn('doc_type', array_values($documentTypes)))
        @if ($queueDocuments->isNotEmpty())
            <ul class="doc-list">
                @foreach ($queueDocuments as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        @php($open = ($openCorrections ?? collect())[$application->id] ?? collect())
        @if ($open->isNotEmpty())
            <div class="haziq-repeatable-row" style="border-color: var(--danger-fg);">
                <p style="grid-column: 1 / -1;"><b>Awaiting student action:</b></p>
                @foreach ($open as $req)
                    <p>{{ $req->target_label }}: <span style="color: var(--text-grey);">{{ $req->remarks }}</span></p>
                @endforeach
            </div>
        @endif

        @if ($canCorrect ?? false)
            @include('haziq::partials.correction_form', [
                'application' => $application,
                'documentTypes' => $documentTypes,
                'sectionLabels' => $sectionLabels,
                'sectionFieldLabels' => $sectionFieldLabels ?? [],
                'requestRoute' => 'ga-application.request-correction',
            ])
        @endif

        @if ($stage->key === 'admin_verify')
            @php($missing = ($missingDocuments ?? collect())[$application->id] ?? collect())
            <p class="queue-meta">Admin verifies or requests correction (above); there is no reject at this stage.</p>
            @if ($missing->isNotEmpty())
                <p class="queue-meta" style="color: var(--danger-fg);">
                    Still missing: {{ $missing->implode(', ') }}. Can't verify until every required document is uploaded.
                </p>
                <button type="button" disabled title="Still missing: {{ $missing->implode(', ') }}">Verify &amp; Advance</button>
            @else
                <form method="POST" action="{{ route('ga-application.decide', $application) }}">
                    @csrf
                    <input type="hidden" name="decision" value="approve">
                    <button type="submit" data-confirm="Verify and advance this application to Research Centre?">Verify &amp; Advance</button>
                </form>
            @endif
        @elseif ($stage->key === 'research_centre_interview')
            @if (! $detail?->interview_date)
                <form method="POST" action="{{ route('ga-application.schedule-interview', $application) }}">
                    @csrf
                    <label for="interview_date-{{ $application->id }}">Interview Date</label>
                    <input type="date" name="interview_date" id="interview_date-{{ $application->id }}" required>
                    @include('haziq::partials.time_picker', ['name' => 'interview_start', 'label' => 'Starts', 'idSuffix' => '-'.$application->id])
                    @include('haziq::partials.time_picker', ['name' => 'interview_end', 'label' => 'Ends', 'idSuffix' => '-'.$application->id])
                    <button type="submit" data-confirm="Schedule this interview? The student will be notified.">Schedule Interview</button>
                </form>
            @else
                <p class="queue-meta">Scheduled for {{ $detail->interview_date->format('j M Y, g:ia') }} to {{ $detail->interview_ends_at?->format('g:ia') ?? '?' }}.</p>
                <form method="POST" action="{{ route('ga-application.decide', $application) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="interview_notes-{{ $application->id }}">Interview Notes <span style="color: var(--text-grey)">(required to fail)</span></label>
                    <textarea id="interview_notes-{{ $application->id }}" name="interview_notes" rows="3"
                              placeholder="Recorded against the interview result."></textarea>
                    <label for="interview_report-{{ $application->id }}">Interview Report</label>
                    <input type="file" name="interview_report" id="interview_report-{{ $application->id }}">
                    <div class="decision-row">
                        <button type="submit" name="decision" value="approve" class="haziq-decide-approve">Pass</button>
                        <button type="submit" name="decision" value="reject" class="btn-reject haziq-decide-reject">Fail</button>
                    </div>
                </form>
            @endif
            @include('haziq::partials.decide_scripts')
        @else
            @include('haziq::partials.decision_form', ['application' => $application, 'route' => route('ga-application.decide', $application)])
        @endif
    </div>
@empty
    <div class="empty-state">Nothing is waiting on you right now.</div>
@endforelse
</div>
@endsection
