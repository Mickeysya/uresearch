@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>
        <b>{{ $detail->isExtension() ? 'Extension' : 'New Application' }}</b>
        &middot; {{ $detail->project_title }}
    </p>
    <p>Project Leader: {{ $detail->project_leader_name }} &middot; Funding: {{ $detail->funding_source }}</p>
    <p>Service: {{ $detail->service_start_date->format('j M Y') }} to {{ $detail->service_end_date->format('j M Y') }}</p>
    <p>Proposed Allowance:
        @if ($detail->proposed_allowance === null)
            <span style="color: var(--text-grey);">not yet set by CGS</span>
        @else
            RM {{ number_format($detail->proposed_allowance, 2) }}
        @endif
        @if ($detail->isExtension()) (was RM {{ number_format($detail->previous_allowance, 2) }}) @endif
    </p>
    @if ($detail->isExtension() && $detail->extension_reason)
        <p>Reason: {{ $detail->extension_reason }}</p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif

@php($open = ($openCorrections ?? collect())[$application->id] ?? collect())
@if ($open->isNotEmpty())
    <div class="haziq-repeatable-row" style="border-color: #D0342C;">
        <p style="grid-column: 1 / -1;"><b>Awaiting student action:</b></p>
        @foreach ($open as $req)
            <p>{{ $req->target_label }} — <span style="color: var(--text-grey);">{{ $req->remarks }}</span></p>
        @endforeach
    </div>
@endif

{{-- Any of the four approver roles reaching this queue page can flag a
     correction — route middleware already restricts who gets here. --}}
@if (isset($documentTypes))
    @include('haziq::partials.correction_form', [
        'application' => $application,
        'documentTypes' => $documentTypes,
        'sectionLabels' => $sectionLabels ?? [],
        'sectionFieldLabels' => $sectionFieldLabels ?? [],
        'requestRoute' => 'gra-application.request-correction',
    ])
@endif
