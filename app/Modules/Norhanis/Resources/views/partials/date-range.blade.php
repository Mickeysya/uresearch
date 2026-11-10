{{--
    Keeps an end date from falling before its start date, in the browser.

    @include('norhanis::partials.date-range', ['start' => 'travel_start_date', 'end' => 'travel_end_date'])

    The end date's min follows the chosen start date (same day allowed).
    When the start date moves past an end date already picked, that end date
    is cleared, so the student picks it again rather than submitting a range
    they did not choose. The server checks the same rule: after_or_equal in
    TravelController::store() and PublicationController::store().
--}}
<script @cspNonce>
(function () {
    var start = document.getElementById(@json($start));
    var end = document.getElementById(@json($end));
    if (! start || ! end) return;

    // A min the end field had before any start date was chosen (Travel: today).
    var ownMin = end.getAttribute('min') || start.getAttribute('min') || '';

    function sync() {
        end.min = start.value || ownMin;

        // ISO dates (YYYY-MM-DD) compare correctly as strings.
        if (start.value && end.value && end.value < start.value) {
            end.value = '';
            end.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    start.addEventListener('change', sync);
    start.addEventListener('input', sync);
    sync();
})();
</script>
