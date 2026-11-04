{{--
    A generic "are you sure?" gate for any submit button — just add
    data-confirm="..." to the button. Used for every plain submit action in
    this module (student's Submit Application/Extension, Admin's Verify &
    Advance) that doesn't need the extra reject-reason logic
    haziq::partials.decision_form has. Safe to @include more than once per
    page — @once means the listener is only ever attached one time.
--}}
@once
    @push('scripts')
        <script @cspNonce>
            (function () {
                document.addEventListener('click', function (event) {
                    var el = event.target.closest('[data-confirm]');
                    if (! el) return;
                    if (! confirm(el.getAttribute('data-confirm'))) {
                        event.preventDefault();
                    }
                });
            })();
        </script>
    @endpush
@endonce
