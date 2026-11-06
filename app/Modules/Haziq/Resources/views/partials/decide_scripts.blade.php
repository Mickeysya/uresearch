{{--
    The confirm + reason-required behaviour for any Approve/Reject-shaped
    pair of buttons carrying class="haziq-decide-approve"/"haziq-decide-reject"
    — shared by haziq::partials.decision_form AND GA's research_centre_interview
    Pass/Fail form, which doesn't use that partial (interview_notes isn't a
    plain remarks field) but wants the identical confirm/require-a-reason
    behaviour. Split out so both can @include it directly — @once is scoped
    to where it's written in the source, so a @once block buried inside
    decision_form.blade.php would never fire on a page that only renders the
    interview form branch.
--}}
@once
    @push('scripts')
        <script @cspNonce>
            (function () {
                // One delegated listener covers every decision form on the
                // page (a queue screen lists many), rather than one script
                // block per row.
                document.addEventListener('click', function (event) {
                    var btn = event.target.closest('.haziq-decide-approve, .haziq-decide-reject');
                    if (! btn) return;

                    // Plain selector, not textarea[name="remarks"] specifically
                    // — GA's interview form reuses these same classes with a
                    // field named interview_notes instead.
                    var form = btn.closest('form');
                    var remarksField = form ? form.querySelector('textarea') : null;
                    var remarks = remarksField ? remarksField.value.trim() : '';

                    var action = btn.textContent.trim(); // "Approve"/"Reject" or "Pass"/"Fail"

                    if (btn.classList.contains('haziq-decide-reject')) {
                        if (! remarks) {
                            event.preventDefault();
                            alert('Please state a reason before choosing "' + action + '" — the student will see it.');
                            if (remarksField) remarksField.focus();
                            return;
                        }
                        if (! confirm('Are you sure you want to ' + action.toLowerCase() + ' this application?\n\nReason: ' + remarks)) {
                            event.preventDefault();
                        }
                        return;
                    }

                    if (! confirm('Are you sure you want to ' + action.toLowerCase() + ' this application?')) {
                        event.preventDefault();
                    }
                });
            })();
        </script>
    @endpush
@endonce
