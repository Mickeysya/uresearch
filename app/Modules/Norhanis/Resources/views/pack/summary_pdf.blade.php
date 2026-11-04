<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    {{-- The Application Summary an approver downloads: what the student
         filled in, where it has been, and what is attached. Rendered by
         ApplicationPackController with Dompdf, so plain CSS and one font. --}}
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        .head { text-align: center; margin-bottom: 14px; }
        .head h1 { font-size: 14px; margin: 0; }
        .head h2 { font-size: 12px; font-weight: normal; margin: 4px 0 0; }
        h3 { font-size: 11px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #1a1a1a; }
        table { width: 100%; border-collapse: collapse; }
        .fields td { padding: 3px 6px; vertical-align: top; }
        .fields td.label { width: 32%; color: #444; }
        .grid th, .grid td { border: 1px solid #999; padding: 4px 6px; vertical-align: top; text-align: left; }
        .grid th { background: #eee; font-weight: bold; }
        .muted { color: #666; }
        .foot { margin-top: 18px; font-size: 8.5px; color: #555; }
    </style>
</head>
<body>
    <div class="head">
        <h1>UNIVERSITI TEKNOLOGI PETRONAS<br>CENTRE FOR GRADUATE STUDIES</h1>
        <h2>APPLICATION SUMMARY: {{ strtoupper($module->label()) }}</h2>
    </div>

    <table class="fields">
        <tr><td class="label">Reference</td><td><b>{{ $application->reference() }}</b> (Application #{{ $application->id }})</td></tr>
        <tr><td class="label">Status</td><td>{{ ucfirst($application->status) }}@if ($stage), waiting on {{ $stage->label }}@endif</td></tr>
        <tr><td class="label">Submitted</td><td>{{ $application->submitted_at?->format('j M Y, g:ia') ?? '—' }}</td></tr>
    </table>

    {{-- ---- Student ---------------------------------------------------- --}}
    <h3>Student</h3>
    <table class="fields">
        <tr><td class="label">Name</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Matric No.</td><td>{{ $student?->matric_no ?? '—' }}</td></tr>
        <tr><td class="label">Email</td><td>{{ $student?->email ?? '—' }}</td></tr>
        <tr><td class="label">Programme</td><td>{{ $student?->programme ?? '—' }}</td></tr>
        <tr><td class="label">Department</td><td>{{ $student?->department ?? '—' }}@if ($student?->faculty) ({{ $student->faculty }})@endif</td></tr>
        <tr><td class="label">Supervisor</td><td>{{ $student?->supervisor?->name ?? '—' }}</td></tr>
    </table>

    {{-- ---- Travel ----------------------------------------------------- --}}
    @if ($travel)
        <h3>Travel Details</h3>
        <table class="fields">
            <tr><td class="label">Type of Request</td><td>
                {{ \App\Modules\Norhanis\Models\TravelDetail::requestTypes()[$travel->type_of_request] ?? $travel->type_of_request }}
                @if ($travel->other_request_specify) ({{ $travel->other_request_specify }}) @endif
            </td></tr>
            <tr><td class="label">Local / International</td><td>{{ $travel->is_international ? 'International' : 'Local' }}</td></tr>
            <tr><td class="label">Travel Dates</td><td>
                {{ $travel->travel_start_date->format('j M Y') }} to {{ $travel->travel_end_date->format('j M Y') }}
                ({{ $travel->duration_days }} {{ \Illuminate\Support\Str::plural('day', $travel->duration_days) }})
            </td></tr>
            <tr><td class="label">Reason for Travelling</td><td>{{ $travel->reason_for_travel }}</td></tr>
            <tr><td class="label">Destination While Travelling</td><td>{{ $travel->destination_address }}</td></tr>
            <tr><td class="label">Cost Centre</td><td>{{ $travel->cost_centre ?: '—' }}</td></tr>
            <tr><td class="label">Contact Person</td><td>
                {{ $travel->contact_person_name ?: '—' }}
                @if ($travel->contact_person_no) · {{ $travel->contact_person_no }} @endif
                @if ($travel->contact_person_email) · {{ $travel->contact_person_email }} @endif
            </td></tr>
            <tr><td class="label">GA / GRA</td><td>{{ $travel->is_ga_gra ? 'Yes' : 'No' }}</td></tr>
            @if ($travel->is_ga_gra && $taPlan)
                <tr><td class="label">TA Hours</td><td>
                    {{ $taPlan->hours_completed }} of {{ $taPlan->total_hours_required }} completed;
                    {{ $taPlan->hours_during_travel }} fall during the travel
                </td></tr>
                <tr><td class="label">TA Management</td><td>
                    {{ \App\Modules\Norhanis\Models\TravelTaPlan::managementMethods()[$taPlan->management_method] ?? $taPlan->management_method }}
                    @if ($taPlan->dates->isNotEmpty())
                        on {{ $taPlan->dates->map(fn ($d) => $d->date->format('j M Y'))->implode(', ') }}
                    @endif
                </td></tr>
            @endif
        </table>
    @endif

    {{-- ---- Claims ----------------------------------------------------- --}}
    @if ($claim)
        <h3>Claim Details</h3>
        <table class="fields">
            <tr><td class="label">Claim Type</td><td>Journal publication claim</td></tr>
            @if ($claim->publication)
                <tr><td class="label">For Publication</td><td>#{{ $claim->publication->application_id }} &middot; {{ $claim->publication->title_of_paper }} ({{ $claim->publication->title_of_conference_journal }})</td></tr>
                <tr><td class="label">Publication Fee</td><td>{{ $claim->publication->currency_type }} {{ number_format((float) $claim->publication->conference_journal_fee, 2) }}</td></tr>
            @endif
            <tr><td class="label">Total Claims</td><td><b>RM {{ number_format((float) $claim->total_claims, 2) }}</b></td></tr>
            <tr><td class="label">Bank Name</td><td>{{ $claim->bank_name }}</td></tr>
            <tr><td class="label">Bank Account No.</td><td>{{ $claim->bank_account_no }}</td></tr>
        </table>
    @endif

    {{-- ---- Publication ------------------------------------------------ --}}
    @if ($publication)
        <h3>Publication Details</h3>
        <table class="fields">
            <tr><td class="label">Type of Request</td><td>{{ $publication->requestTypeLabel() }}</td></tr>
            <tr><td class="label">Title of Paper</td><td>{{ $publication->title_of_paper }}</td></tr>
            <tr><td class="label">Conference / Journal</td><td>{{ $publication->title_of_conference_journal }}</td></tr>
            <tr><td class="label">Organizer / Publisher</td><td>{{ $publication->organizer_publisher }}</td></tr>
            <tr><td class="label">Venue</td><td>{{ $publication->venue ?: '—' }}</td></tr>
            <tr><td class="label">Dates</td><td>{{ $publication->conference_start_date?->format('j M Y') }} to {{ $publication->conference_end_date?->format('j M Y') }}</td></tr>
            <tr><td class="label">Fee</td><td>{{ $publication->currency_type }} {{ number_format((float) $publication->conference_journal_fee, 2) }}</td></tr>
            <tr><td class="label">Cost Centre to be Utilized</td><td>{{ $publication->costCentreLabel() }}</td></tr>
        </table>

        <h3>Authors</h3>
        <table class="grid">
            <tr><th>#</th><th>Name</th><th>Email</th><th>Designation</th><th>Organisation</th><th>Role / Contribution</th><th>Certified</th></tr>
            @foreach ($publication->authors as $author)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $author->author_name }}</td>
                    <td>{{ $author->email }}</td>
                    <td>{{ $author->designation ?: '—' }}</td>
                    <td>{{ $author->organisation ?: '—' }}</td>
                    <td>{{ $author->role_contribution ?: '—' }}</td>
                    <td>{{ $author->signed_at?->format('j M Y') ?? 'Not yet' }}</td>
                </tr>
            @endforeach
        </table>

        @if ($publication->evaluations->isNotEmpty())
            <h3>Internal Reviewers (Paper Evaluation)</h3>
            <table class="grid">
                <tr><th>#</th><th>Reviewer</th><th>Recommendation</th><th>Signed</th><th>Student's Response</th></tr>
                @foreach ($publication->evaluations as $evaluation)
                    <tr>
                        <td>{{ $evaluation->position }}</td>
                        <td>
                            {{ $evaluation->reviewer_name }}<br><span class="muted">{{ $evaluation->reviewer_email }}</span>
                            @if ($evaluation->isReplaced()) <br><span class="muted">(replaced)</span> @endif
                        </td>
                        <td>{{ $evaluation->isSubmitted() ? $evaluation->recommendationLabel() : ($evaluation->isDeclined() ? 'Unable to review' : '—') }}</td>
                        <td>{{ $evaluation->signed_at?->format('j M Y') ?? '—' }}</td>
                        <td>{{ $evaluation->response ?: '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    {{-- ---- Approval ---------------------------------------------------- --}}
    <h3>Approval Chain</h3>
    <table class="grid">
        <tr><th>Stage</th><th>State</th></tr>
        @foreach ($application->progress() as $step)
            <tr>
                <td>{{ $step['stage']->label }}</td>
                <td>{{ ['completed' => 'Done', 'current' => 'Waiting here', 'rejected' => 'Rejected here', 'upcoming' => 'Not reached'][$step['state']] ?? ucfirst($step['state']) }}</td>
            </tr>
        @endforeach
    </table>

    <h3>Approval History</h3>
    @if ($history->isEmpty())
        <p class="muted">No decisions yet.</p>
    @else
        <table class="grid">
            <tr><th>Date</th><th>Stage</th><th>Decision</th><th>By</th><th>Remarks</th></tr>
            @foreach ($history as $entry)
                <tr>
                    <td>{{ $entry->created_at?->format('j M Y, g:ia') }}</td>
                    <td>{{ $entry->stage_label }}</td>
                    <td>{{ ucfirst($entry->decision) }}</td>
                    <td>{{ $entry->approver?->name ?? '—' }}</td>
                    <td>{{ $entry->remarks ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ---- Attachments ------------------------------------------------- --}}
    <h3>Attachments</h3>
    @if ($documents->isEmpty())
        <p class="muted">No files attached.</p>
    @else
        <table class="grid">
            <tr><th>Document</th><th>File</th><th>Added</th>@if ($included !== null)<th>In this ZIP</th>@endif</tr>
            @foreach ($documents as $document)
                <tr>
                    <td>{{ $document->doc_type }}</td>
                    <td>{{ $document->original_name }}</td>
                    <td>{{ $document->created_at?->format('j M Y') }}</td>
                    @if ($included !== null)
                        <td>{{ in_array($document->id, $included, true) ? 'Yes' : 'No: opens once the application reaches your stage' }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    <p class="foot">
        Generated {{ now()->format('j M Y, g:ia') }} by {{ $generatedBy->name }}
        ({{ \App\Modules\Core\Support\Role::label($generatedBy->role) }}) from UResearch 2.0.
        This summary reflects the application at that moment.
    </p>
</body>
</html>
