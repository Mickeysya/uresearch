@extends('core::layouts.app')

@section('title', 'GRA Application #' . $application->id)

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="{{ $application->student->name }}: GRA Application #{{ $application->id }}" />

    <div class="card card-wide">
        <x-core::stepper :application="$application" />

        <p class="queue-meta" style="margin-top: 16px;">
            Status: <x-core::status-badge :status="$application->status" />
            @if ($stage) &middot; currently with <b>{{ $stage->label }}</b> @endif
        </p>

        {{-- The appointment's own lifecycle, separate from the application's
             workflow status above -- approved, then active once
             service_start_date arrives, then inactive once
             service_end_date passes with no approved extension covering
             today. Confirmed explicitly 2026-11-10. Skipped once
             terminated: the banner below already covers that case.
             Reads through currentInChain(), not $detail directly, same
             fix as Stage Gate Monitoring's own -- this is still the
             ORIGINAL application's own page (its sections, documents,
             corrections all stay this one's), only the status/dates
             shown here defer to the current extension once one is
             approved, confirmed explicitly 2026-11-10: "pastu sama
             jugak status shown kat my gra application tu." --}}
        @php($currentDetail = $detail->currentInChain())
        @php($serviceStatus = $application->status === \App\Modules\Core\Models\Application::STATUS_APPROVED ? $currentDetail->serviceStatus() : null)
        @if ($serviceStatus && $serviceStatus !== 'terminated')
            <p class="queue-meta">
                Appointment status: <x-core::status-badge :status="$serviceStatus" />
                @if ($serviceStatus === 'approved')
                    Starts {{ $currentDetail->service_start_date->format('j M Y') }}.
                @elseif ($serviceStatus === 'inactive')
                    Service ended {{ $currentDetail->service_end_date->format('j M Y') }}.
                @endif
            </p>
        @endif

        @if ($terminated ?? false)
            <div class="haziq-repeatable-row" style="border-color: var(--danger-fg); background: var(--danger-bg);">
                <p style="grid-column: 1 / -1;">
                    <b>This appointment has been terminated</b>: a Stage Gate requirement was not completed
                    within its grace period.
                    @if ($application->student_id === auth()->id())
                        You can submit a new GRA application any time.
                    @endif
                </p>
            </div>
        @endif

        @if ($openCorrections->isNotEmpty())
            <div class="haziq-repeatable-row" style="border-color: var(--danger-fg);">
                <p style="grid-column: 1 / -1;"><b>Awaiting student action:</b></p>
                @foreach ($openCorrections as $req)
                    <p>{{ $req->target_label }}: <span style="color: var(--text-grey);">{{ $req->remarks }}</span></p>
                @endforeach
            </div>
        @endif

        <h3>Section B: Project Details</h3>
        <div class="haziq-repeatable-row">
            <p><b>Project Title</b><br>{{ $detail->project_title }}</p>
            <p><b>Project Leader</b><br>{{ $detail->project_leader_name }}</p>
            <p><b>Funding Source</b><br>{{ $detail->funding_source }}</p>
            <p><b>Grant</b><br>{{ $detail->grant_name ?? '—' }}</p>
            <p><b>Cost Centre</b><br>{{ $detail->cost_centre ?? '—' }}</p>
            <p><b>Centre</b><br>{{ $detail->centre_name ?? '—' }}</p>
            @if ($detail->isExtension())
                <p><b>Funding Continuation</b><br>{{ $detail->funding_continuation === 'new' ? 'New Grant' : 'Existing Grant' }}</p>
            @endif
            <p><b>Proposed Allowance</b><br>
                RM {{ number_format($detail->proposed_allowance, 2) }}
                @if ($detail->isExtension() && $detail->previous_allowance !== null) (was RM {{ number_format($detail->previous_allowance, 2) }}) @endif
            </p>
        </div>

        <h3>Section C: GRA Details</h3>
        @if ($profile)
            <div class="haziq-repeatable-row">
                <p><b>Programme</b><br>{{ $profile->programme }} ({{ strtoupper($profile->level_of_study) }})</p>
                <p><b>Admission Date</b><br>{{ $profile->admission_date->format('j M Y') }}</p>
                <p><b>Period Candidature</b><br>{{ $profile->candidature_period ?? '—' }}</p>
                <p><b>Mode of Study</b><br>{{ $profile->mode_of_study ?? '—' }}</p>
                <p><b>Thesis Title</b><br>{{ $profile->thesis_title ?? '—' }}</p>
                <p><b>Co-Supervisor</b><br>{{ $profile->coSupervisor?->name ?? '—' }}</p>
                <p><b>Bank</b><br>{{ $profile->bank_name ?? '—' }} · {{ $profile->bank_account_no ?? '—' }}</p>
                <p><b>IC/Passport</b><br>{{ $profile->ic_passport_no ?? '—' }}</p>
                <p><b>Previous Sponsorship</b><br>
                    @if ($profile->previous_sponsorship_name)
                        {{ $profile->previous_sponsorship_name }} ({{ $profile->previous_sponsorship_duration ?? '—' }})
                    @else
                        —
                    @endif
                </p>
                <p><b>Scholarship</b><br>
                    @if ($profile->scholarship_name)
                        {{ $profile->scholarship_name }} ({{ $profile->scholarship_duration ?? '—' }})
                    @else
                        —
                    @endif
                </p>
            </div>
        @endif
        <p>Service: {{ $detail->service_start_date->format('j M Y') }} to {{ $detail->service_end_date->format('j M Y') }}</p>

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
                <p>{{ $row->publication_year ?? '—' }}</p>
                <p>{{ $row->journal_or_conference ?? '—' }}</p>
                <p>{{ $row->volume_chapter ?? '—' }} @if ($row->page_number) &middot; p. {{ $row->page_number }} @endif</p>
                <p>{{ $row->doi ?? '—' }}</p>
                <p>{{ $row->issn ?? '—' }}</p>
                <p>{{ $row->database_quartile ?? '—' }} @if ($row->impact_factor) &middot; IF {{ $row->impact_factor }} @endif</p>
            </div>
        @empty
            <p style="color: var(--text-grey);">None on record.</p>
        @endforelse

        <h3>Documents</h3>
        {{-- Exactly the Section A checklist, same scope as the correction
             form's own upload loop — not the generated Offer/Admission
             letters (Mailpit has those), not Proof of Payment (Allowance
             Eligibility has that), not a Candidature Appeal Letter (the
             appeal's own queue/detail page has that). --}}
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
                'requestRoute' => 'gra-application.request-correction',
            ])
        @endif

        @if ($canDecide)
            <h3>Your Decision</h3>
            @if ($stage->key === 'admin_verify')
                <form method="POST" action="{{ route('gra-application.decide', $application) }}">
                    @csrf
                    <input type="hidden" name="decision" value="approve">
                    <p class="queue-meta">Admin verifies or requests correction (above); there is no reject at this stage.</p>
                    <button type="submit" data-confirm="Verify and advance this application to CGS?">Verify &amp; Advance to CGS</button>
                </form>
            @else
                @include('haziq::partials.decision_form', ['application' => $application, 'route' => route('gra-application.decide', $application)])
            @endif
        @endif

        @include('haziq::partials.confirm_scripts')

        {{-- Student's own view only: shown while pending, and again once
             approved as long as the appointment hasn't since been
             terminated by a Stage Gate — never on a rejected application
             (the student resubmits a fresh one instead) or a terminated
             one (same). --}}
        @if ($canEdit ?? false)
            <div style="margin-top: 20px;">
                <a href="{{ route('gra-application.correction.edit', $application) }}"
                   style="display: inline-block; padding: 9px 18px; border-radius: 6px; text-decoration: none;"
                   class="btn-secondary">Edit</a>
            </div>
        @endif
    </div>
</div>
@include('haziq::partials.assistant_widget')
@endsection
