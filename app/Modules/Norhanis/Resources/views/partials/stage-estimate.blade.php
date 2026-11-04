{{--
    When an open application should be decided, against the CGS client
    charter. One line under the stepper: nothing for a closed application.
    See Support/StageDurations for where the date comes from.
--}}
@php($estimate = app(\App\Modules\Norhanis\Support\StageDurations::class)->estimate($application))

@if ($estimate)
    <p class="stage-estimate" style="margin: 8px 0 12px;">
        <b>Expected final decision:</b> around {{ $estimate['expected']->format('D, j M Y') }}
    </p>
@endif
