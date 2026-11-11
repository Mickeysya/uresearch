@extends('core::layouts.app')

@section('title', 'GA Application #' . $application->id)

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="{{ $application->student->name }}: GA Application #{{ $application->id }}" />

    <div class="card card-wide">
        <x-core::stepper :application="$application" />

        <p class="queue-meta" style="margin-top: 16px;">
            Status: <x-core::status-badge :status="$application->status" />
            @if ($stage) &middot; currently with <b>{{ $stage->label }}</b> @endif
        </p>

        {{-- Same appointment-lifecycle concept as GRA's own show page --
             see GraApplicationDetail::serviceStatus()'s docblock.
             Confirmed explicitly 2026-11-10. --}}
        @php($serviceStatus = $application->status === \App\Modules\Core\Models\Application::STATUS_APPROVED ? $detail->serviceStatus() : null)
        @if ($serviceStatus && $serviceStatus !== 'terminated')
            <p class="queue-meta">
                Appointment status: <x-core::status-badge :status="$serviceStatus" />
                @if ($serviceStatus === 'approved')
                    Starts {{ $detail->service_start_date->format('j M Y') }}.
                @elseif ($serviceStatus === 'inactive')
                    Service ended {{ $detail->service_end_date->format('j M Y') }}.
                @endif
            </p>
        @endif

        @if ($openCorrections->isNotEmpty())
            <div class="haziq-repeatable-row" style="border-color: var(--danger-fg);">
                <p style="grid-column: 1 / -1;"><b>Awaiting student action:</b></p>
                @foreach ($openCorrections as $req)
                    <p>{{ $req->target_label }}: <span style="color: var(--text-grey);">{{ $req->remarks }}</span></p>
                @endforeach
            </div>
        @endif

        <h3>Section B: GA Details</h3>
        <div class="haziq-repeatable-row">
            <p><b>Department / Unit</b><br>{{ $detail->department_unit ?? '—' }}</p>
            <p><b>Purpose</b><br>{{ $detail->purpose ?? '—' }}</p>
            <p><b>Proposed Hours/Week</b><br>{{ $detail->proposed_hours_per_week ?? '—' }}</p>
            <p><b>Service</b><br>{{ $detail->service_start_date->format('j M Y') }} to {{ $detail->service_end_date->format('j M Y') }}</p>
            <p><b>Proposed Allowance</b><br>RM {{ number_format($detail->proposed_allowance, 2) }}</p>
        </div>

        <h3>Section C: Candidate Details</h3>
        @if ($profile)
            <div class="haziq-repeatable-row">
                <p><b>Programme</b><br>{{ $profile->programme }} ({{ strtoupper($profile->level_of_study) }})</p>
                <p><b>Admission Date</b><br>{{ $profile->admission_date->format('j M Y') }}</p>
            </div>
        @endif

        <h3>Interview</h3>
        <div class="haziq-repeatable-row">
            <p><b>Scheduled</b><br>{{ $detail->interview_date ? $detail->interview_date->format('j M Y, g:ia').' to '.($detail->interview_ends_at?->format('g:ia') ?? '?') : 'Not yet scheduled' }}</p>
            <p><b>Result</b><br>{{ ucfirst($detail->interview_result) }}</p>
            @if ($detail->interview_notes)
                <p style="grid-column: 1 / -1;"><b>Notes</b><br>{{ $detail->interview_notes }}</p>
            @endif
            {{-- Already in the generic Documents list below too; repeated
                 here so it sits right next to the result it belongs to,
                 instead of making the student go hunting for it. --}}
            @php($interviewReport = $application->documents->firstWhere('doc_type', 'Interview Report'))
            @if ($interviewReport)
                <p style="grid-column: 1 / -1;"><b>Interview Report</b><br><a href="{{ $interviewReport->url() }}">{{ $interviewReport->original_name }}</a></p>
            @endif
        </div>

        <h3>Section D: Academic Qualification</h3>
        @forelse ($academics as $row)
            <div class="haziq-repeatable-row">
                <p>{{ $row->level_of_study }}</p>
                <p>{{ $row->institution }}</p>
                <p>{{ $row->programme }}</p>
                <p>{{ $row->result }}</p>
                <p>{{ $row->year_from }}–{{ $row->year_to }}</p>
            </div>
        @empty
            <p style="color: var(--text-grey);">None on record.</p>
        @endforelse

        <h3>Section E: Working Experience</h3>
        @forelse ($experiences as $row)
            <div class="haziq-repeatable-row">
                <p>{{ $row->company_name }}</p>
                <p>{{ $row->designation }}</p>
                <p>{{ $row->date_from?->format('M Y') }} – {{ $row->date_to?->format('M Y') ?? 'Present' }}</p>
                <p>{{ $row->reason_for_leaving ?? '—' }}</p>
            </div>
        @empty
            <p style="color: var(--text-grey);">None on record.</p>
        @endforelse

        <h3>Section F: Publications</h3>
        @forelse ($publications as $row)
            <div class="haziq-repeatable-row">
                <p>{{ $row->publication_type }}</p>
                <p>{{ $row->title }}</p>
                <p>{{ $row->authors }}</p>
                <p>{{ $row->journal_or_conference ?? '—' }}</p>
            </div>
        @empty
            <p style="color: var(--text-grey);">None on record.</p>
        @endforelse

        <h3>Documents</h3>
        {{-- Exactly the Section A checklist — see
             GraApplicationController::show.blade.php's identical note for
             why the generated letters, Proof of Payment and a Candidature
             Appeal Letter are all left out here. --}}
        @php($applicationDocuments = $application->documents->whereIn('doc_type', array_values($documentTypes)))
        @if ($applicationDocuments->isNotEmpty())
            <ul class="doc-list">
                @foreach ($applicationDocuments as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @else
            <p style="color: var(--text-grey);">No documents uploaded.</p>
        @endif

        @if (($viewerIsStudent ?? false) && ($missingDocuments ?? collect())->isNotEmpty())
            <div class="alert-banner alert-banner-warning" role="status">
                <strong>Still missing {{ $missingDocuments->count() }} {{ Str::plural('document', $missingDocuments->count()) }}:</strong>
                {{ $missingDocuments->implode(', ') }}.
                Your application keeps moving through the approval chain either way, so upload these whenever you can.
            </div>
        @endif

        <h3>Approval History</h3>
        @forelse ($application->history as $entry)
            <p>
                <b>{{ $entry->stage_label }}</b>: {{ $entry->decision }} by {{ $entry->approver?->name ?? '—' }}
                <span style="color: var(--text-grey);">({{ $entry->created_at->format('j M Y, g:ia') }})</span>
                @if ($entry->remarks) <br><span style="color: var(--text-grey);">"{{ $entry->remarks }}"</span> @endif
            </p>
        @empty
            <p style="color: var(--text-grey);">No decisions recorded yet.</p>
        @endforelse

        @if ($resolvedCorrections->isNotEmpty())
            <h3>Resolved Corrections</h3>
            @foreach ($resolvedCorrections as $req)
                <p>{{ $req->target_label }}: requested {{ $req->requested_at->format('j M Y') }},
                   resolved {{ $req->resolved_at->format('j M Y') }}</p>
            @endforeach
        @endif

        @if ($canCorrect)
            <h3>Request Correction</h3>
            <p class="queue-meta">Any approver can flag a section or document; this doesn't reject the application, it just sends the student back to fix the one thing flagged.</p>
            @include('haziq::partials.correction_form', [
                'application' => $application,
                'documentTypes' => $documentTypes,
                'sectionLabels' => $sectionLabels,
                'sectionFieldLabels' => $sectionFieldLabels,
                'requestRoute' => 'ga-application.request-correction',
            ])
        @endif

        @if ($canDecide && $stage?->key === 'research_centre_interview')
            <h3>Interview</h3>
            @if (! $detail->interview_date)
                <form method="POST" action="{{ route('ga-application.schedule-interview', $application) }}">
                    @csrf
                    <label for="interview_date">Interview Date</label>
                    <input type="date" name="interview_date" id="interview_date" required>
                    @include('haziq::partials.time_picker', ['name' => 'interview_start', 'label' => 'Starts'])
                    @include('haziq::partials.time_picker', ['name' => 'interview_end', 'label' => 'Ends'])
                    <button type="submit" data-confirm="Schedule this interview? The student will be notified.">Schedule Interview</button>
                </form>
            @else
                <p class="queue-meta">Scheduled for {{ $detail->interview_date->format('j M Y, g:ia') }} to {{ $detail->interview_ends_at?->format('g:ia') ?? '?' }}.</p>
                <form method="POST" action="{{ route('ga-application.decide', $application) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="interview_notes">Interview Notes <span style="color: var(--text-grey)">(required to fail)</span></label>
                    <textarea id="interview_notes" name="interview_notes" rows="3"></textarea>

                    <label for="interview_report">Interview Report</label>
                    <input type="file" name="interview_report" id="interview_report">
                    @error('interview_report') <p class="field-error">{{ $message }}</p> @enderror

                    <div class="decision-row">
                        <button type="submit" name="decision" value="approve" class="haziq-decide-approve" data-confirm="Record this interview as a PASS? CGS will be notified.">Pass</button>
                        <button type="submit" name="decision" value="reject" class="btn-reject haziq-decide-reject" data-confirm="Record this interview as a FAIL? The application will be rejected outright.">Fail</button>
                    </div>
                </form>
            @endif
        @elseif ($canDecide && $stage?->key === 'admin_verify')
            <h3>Your Decision</h3>
            <form method="POST" action="{{ route('ga-application.decide', $application) }}">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <p class="queue-meta">Admin verifies or requests correction (above); there is no reject at this stage.</p>
                <button type="submit" data-confirm="Verify and advance this application to Research Centre?">Verify &amp; Advance</button>
            </form>
        @elseif ($canDecide)
            <h3>Your Decision</h3>
            @include('haziq::partials.decision_form', ['application' => $application, 'route' => route('ga-application.decide', $application)])
        @endif

        @include('haziq::partials.confirm_scripts')

        @if ($canEdit ?? false)
            <div style="margin-top: 20px;">
                <a href="{{ route('ga-application.correction.edit', $application) }}"
                   style="display: inline-block; padding: 9px 18px; border-radius: 6px; text-decoration: none;"
                   class="btn-secondary">Edit</a>
            </div>
        @endif
    </div>
</div>
@include('haziq::partials.assistant_widget')
@endsection
