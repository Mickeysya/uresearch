{{--
    "Urgent · departs in 3 days": shown while a trip is still pending and
    starts on or before the date its final decision is expected, so the
    approver can take it first. Nothing for a closed application, or for a
    trip with time to spare. See Support/StageDurations for the date.
--}}
@php($estimate = app(\App\Modules\Norhanis\Support\StageDurations::class)->estimate($application))

@if ($estimate && $detail->travel_start_date->lte($estimate['expected']->copy()->startOfDay()))
    {{-- (int), because Carbon 3 returns a float here. --}}
    @php($days = (int) today()->diffInDays($detail->travel_start_date, false))
    <p class="message-warning travel-urgency">
        <b>Urgent</b> &middot;
        @if ($days > 0)
            departs in {{ $days }} {{ Str::plural('day', $days) }}
        @elseif ($days === 0)
            departs today
        @else
            departed {{ -$days }} {{ Str::plural('day', -$days) }} ago
        @endif
        &middot; final decision expected around {{ $estimate['expected']->format('D, j M Y') }}
    </p>
@endif
