{{-- Travel-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @include('norhanis::travel._urgency')
    <p>Reason for Travelling: {{ $detail->reason_for_travel }}</p>
    <p>Destination While Travelling: {{ $detail->destination_address }}</p>
    @if ($detail->cost_centre) <p>Cost Centre: {{ $detail->cost_centre }}</p> @endif
    <p>Dates: {{ $detail->travel_start_date->format('j M Y') }}
       to {{ $detail->travel_end_date->format('j M Y') }}
       ({{ $detail->duration_days }} {{ Str::plural('day', $detail->duration_days) }})</p>
    <p>Type: <b>{{ $detail->is_international ? 'International' : 'Local' }}</b>
       &middot; {{ \App\Modules\Norhanis\Models\TravelDetail::requestTypes()[$detail->type_of_request] ?? $detail->type_of_request }}
       @if ($detail->other_request_specify) ({{ $detail->other_request_specify }}) @endif
    </p>
    @if ($detail->contact_person_name)
        <p>Contact: {{ $detail->contact_person_name }} {{ $detail->contact_person_no }}
           @if ($detail->contact_person_email) ({{ $detail->contact_person_email }}) @endif</p>
    @endif

    @if ($detail->is_ga_gra)
        @php($taPlan = ($taPlans ?? collect())[$application->id] ?? null)
        <p>TA Plan:
            @if ($taPlan)
                {{ $taPlan->hours_completed }}h completed, {{ $taPlan->hours_during_travel }}h during travel
                &middot; {{ \App\Modules\Norhanis\Models\TravelTaPlan::managementMethods()[$taPlan->management_method] ?? $taPlan->management_method }}
            @else
                <span style="color: var(--text-grey);">not recorded</span>
            @endif
        </p>
    @else
        <p>TA Plan: <span style="color: var(--text-grey);">Not a GA/GRA recipient</span></p>
    @endif

    <p><a href="{{ route('travel.show', $application) }}">View full application</a></p>
    @include('norhanis::partials.stage-estimate', ['application' => $application])
    @include('norhanis::pack._buttons', ['application' => $application])
    @include('norhanis::pack._inline_documents', ['application' => $application])
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
