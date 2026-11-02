@extends('core::layouts.app')

@section('title', 'Travel Application #' . $application->id)

@section('content')
    <x-core::page-header title="Travel Application #{{ $application->id }}">
        @if ($canUpdateDocument)
            <a href="{{ route('travel.document.edit', $application) }}" class="btn-secondary">Update Supporting Document</a>
        @endif
    </x-core::page-header>

    <p class="queue-meta">
        <x-core::status-badge :status="$application->status" />
        &nbsp;Submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}
    </p>

    <div class="app-item">
        <p>{{ $detail->reason_for_travel }}, {{ $detail->destination_address }}</p>

        <x-core::stepper :application="$application" />

        @if ($rejection = $application->rejection())
            <div class="rejection-note">
                Rejected at {{ $rejection->stage_label }}.
                @if ($rejection->remarks) Remarks: {{ $rejection->remarks }} @endif
            </div>
        @endif

        <p>Type: <b>{{ $detail->is_international ? 'International' : 'Local' }}</b>
           &middot; {{ \App\Modules\Norhanis\Models\TravelDetail::requestTypes()[$detail->type_of_request] ?? $detail->type_of_request }}
           @if ($detail->other_request_specify) ({{ $detail->other_request_specify }}) @endif
        </p>
        @if ($detail->cost_centre) <p>Cost Centre: {{ $detail->cost_centre }}</p> @endif
        <p>Dates: {{ $detail->travel_start_date->format('j M Y') }}
           to {{ $detail->travel_end_date->format('j M Y') }}
           ({{ $detail->duration_days }} {{ Str::plural('day', $detail->duration_days) }})</p>

        @if ($detail->contact_person_name || $detail->contact_person_no || $detail->contact_person_email)
            <p>Contact: {{ $detail->contact_person_name }} {{ $detail->contact_person_no }}
               @if ($detail->contact_person_email) &middot; {{ $detail->contact_person_email }} @endif
            </p>
        @endif

        <h4>Teaching Assistant (TA) Plan</h4>
        @if (! $detail->is_ga_gra)
            <p style="color: var(--text-grey);">Not a GA/GRA recipient</p>
        @elseif ($taPlan)
            <p>Total TA hours required this semester: {{ $taPlan->total_hours_required }}</p>
            <p>TA hours completed to date: {{ $taPlan->hours_completed }}</p>
            <p>TA hours scheduled during the travel period: {{ $taPlan->hours_during_travel }}</p>
            <p>How the affected TA hours will be managed:
               {{ \App\Modules\Norhanis\Models\TravelTaPlan::managementMethods()[$taPlan->management_method] ?? $taPlan->management_method }}</p>
            @if ($taPlan->dates->isNotEmpty())
                <p>Proposed replacement/rescheduled date(s):
                   {{ $taPlan->dates->map(fn ($d) => $d->date->format('j M Y'))->implode(', ') }}</p>
            @endif
        @else
            <p style="color: var(--text-grey);">No TA plan recorded for this application.</p>
        @endif

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        <div class="history-trail">
            @forelse ($application->history as $entry)
                <div class="entry">
                    <b>{{ $entry->stage_label }}</b>: {{ $entry->decision }}
                    by {{ $entry->approver->name }}
                    on {{ $entry->created_at->format('j M Y, g:ia') }}
                    @if ($entry->remarks) "{{ $entry->remarks }}" @endif
                </div>
            @empty
                <div class="entry">No decisions recorded yet.</div>
            @endforelse
        </div>
    </div>
@endsection
