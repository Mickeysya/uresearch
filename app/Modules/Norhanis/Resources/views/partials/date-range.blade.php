{{--
    Keeps an end date from falling before its start date, in the browser.

    @include('norhanis::partials.date-range', ['start' => 'travel_start_date', 'end' => 'travel_end_date'])

    The end date's min follows the chosen start date (same day allowed).
    When the start date moves past an end date already picked, that end date
    is cleared, so the student picks it again rather than submitting a range
    they did not choose. The server checks the same rule: after_or_equal in
    TravelController::store() and PublicationController::store(), both from
    Support\TravelDates.

    Optional 'duration' => the id of a read-only field to fill with the
    number of days, both ends counted (1 Dec to 1 Dec is 1 day), as
    TravelDates::durationDays() works it out on the server.
--}}
<script @cspNonce>
(function () {
    var start = document.getElementById(@json($start));
    var end = document.getElementById(@json($end));
    var duration = document.getElementById(@json($duration ?? ''));
    if (! start || ! end) return;

    function days() {
        if (! duration) return;
        if (! start.value || ! end.value || end.value < start.value) { duration.value = ''; return; }
        // Midday UTC on both, so a daylight-saving change cannot shift a day.
        var ms = Date.parse(end.value + 'T12:00:00Z') - Date.parse(start.value + 'T12:00:00Z');
        duration.value = String(Math.round(ms / 86400000) + 1);
    }

    // A min the end field had before any start date was chosen (Travel: today).
    var ownMin = end.getAttribute('min') || start.getAttribute('min') || '';

    function sync() {
        end.min = start.value || ownMin;

        // ISO dates (YYYY-MM-DD) compare correctly as strings.
        if (start.value && end.value && end.value < start.value) {
            end.value = '';
            end.dispatchEvent(new Event('change', { bubbles: true }));
        }

        days();
    }

    start.addEventListener('change', sync);
    start.addEventListener('input', sync);
    end.addEventListener('change', days);
    end.addEventListener('input', days);
    sync();
})();
</script>
