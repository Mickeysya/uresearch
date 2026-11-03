{{--
    Save-draft behaviour for a form-stepper form marked data-draft-form
    (GA Extension, GA/GRA Certification). Runs after Core's form-stepper
    script, and only uses what it exposes in the page:

      - "Save draft" moves into the stepper's navigation, beside Submit, so
        it is there on every step (Submit itself only shows on the last);
      - saving records which step the student is on (data-draft-step);
      - continuing a draft reopens on that step by pressing the stepper's
        own Continue, one step at a time, for as long as each step's fields
        are valid -- so it stops early on a step that still needs something,
        and never skips the stepper's checks. If the server has just
        reported an error, the stepper's own "open on the error" wins.

    Without JavaScript the form is one long page with both buttons, and
    everything still works.
--}}
@once
    @push('scripts')
        <script @cspNonce>
        (function () {
            Array.prototype.forEach.call(document.querySelectorAll('form[data-draft-form]'), function (form) {
                var save = form.querySelector('[data-save-draft]');
                var stepInput = form.querySelector('[data-draft-step]');
                var nav = form.querySelector('.fstep-nav');
                var rail = document.querySelector('.form-steps');
                var steps = form.querySelectorAll('fieldset.fstep');

                function currentStep() {
                    if (! rail) return 0;
                    var found = Array.prototype.findIndex.call(rail.children, function (li) {
                        return li.classList.contains('is-current');
                    });
                    return found < 0 ? 0 : found;
                }

                function stepIsValid(i) {
                    var step = steps[i];
                    if (! step) return false;
                    return Array.prototype.every.call(step.querySelectorAll('input, select, textarea'), function (field) {
                        return field.checkValidity();
                    });
                }

                if (nav && save) {
                    nav.insertBefore(save, nav.querySelector('button[type="submit"]'));
                    // Line up with Continue: shared styles give submit
                    // buttons a different top margin.
                    var cont = nav.querySelector('[data-fstep-next]');
                    if (cont) save.style.marginTop = getComputedStyle(cont).marginTop;
                }

                if (save && stepInput) {
                    save.addEventListener('click', function () {
                        stepInput.value = currentStep();
                    });
                }

                var target = parseInt(form.dataset.resumeStep || '0', 10);
                var next = form.querySelector('[data-fstep-next]');

                if (target > 0 && next && ! form.querySelector('.is-invalid, .field-error')) {
                    while (currentStep() < target && ! next.hidden && stepIsValid(currentStep())) {
                        var before = currentStep();
                        next.click();
                        if (currentStep() === before) break;
                    }
                }
            });
        })();
        </script>
    @endpush
@endonce
