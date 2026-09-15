{{--
    Turns a long single-page form into a wizard, without changing anything
    on the server.

    HOW TO USE IT — two edits to your form, no controller change:

        <form method="POST" ... data-stepper>
            @csrf
            <fieldset class="fstep" data-label="Trip details"> ...fields... </fieldset>
            <fieldset class="fstep" data-label="Documents">    ...fields... </fieldset>
            <button type="submit">Submit Application</button>
        </form>
        @include('core::partials.form-stepper')

    Everything else -- the progress rail, Back/Continue, the review step,
    moving your submit button to the end -- is built here.

    WHY CLIENT-SIDE. The form still POSTs once, to the same route, with the
    same fields. No session state, no partial validation, no resume logic,
    and $request->validate() in the controller is untouched. A server-side
    wizard would need all four and buys nothing a student can see.

    WHAT HAPPENS WITHOUT JAVASCRIPT. Every .fstep is visible by default --
    the rules that hide them are added by the script itself. So the form
    degrades to exactly what it was before: one long page, one submit
    button. Nothing is gated behind script that the server does not also
    enforce.

    THE REVIEW STEP IS GENERATED, not authored. It reads the filled
    controls back out of the form, so it cannot drift from the fields and
    no module has to write or maintain one. A student sees what they are
    about to send to four approvers before it goes.
--}}
@once
    @push('scripts')
        <script @cspNonce>
        (function () {
            var form = document.querySelector('form[data-stepper]');
            if (! form) return;

            var steps = Array.prototype.slice.call(form.querySelectorAll('.fstep'));
            if (! steps.length) return;

            var REVIEW = 'Review';

            /* ---- review step ------------------------------------------
               Built from the form's own controls. A control is described
               by its <label for>, falling back to a wrapping label, then
               to the name attribute. Empty, hidden, disabled and
               unchecked controls are skipped: a review that lists forty
               blanks is not a review.
               --------------------------------------------------------- */
            function labelFor(field) {
                var byFor = field.id && form.querySelector('label[for="' + CSS.escape(field.id) + '"]');
                if (byFor) return byFor.textContent.trim().replace(/\s+/g, ' ');

                var wrapping = field.closest('label');
                if (wrapping) return wrapping.textContent.trim().replace(/\s+/g, ' ');

                return (field.name || '').replace(/\[|\]/g, ' ').replace(/_/g, ' ').trim();
            }

            function valueOf(field) {
                if (field.type === 'checkbox') return field.checked ? 'Yes' : '';
                if (field.type === 'radio') return field.checked ? field.value : '';

                if (field.type === 'file') {
                    if (! field.files || ! field.files.length) return '';
                    return Array.prototype.map.call(field.files, function (f) { return f.name; }).join(', ');
                }

                if (field.tagName === 'SELECT') {
                    var opt = field.options[field.selectedIndex];
                    return opt ? opt.textContent.trim() : '';
                }

                return (field.value || '').trim();
            }

            function buildReview(into) {
                into.innerHTML = '';

                steps.forEach(function (step) {
                    if (step.dataset.label === REVIEW) return;

                    var rows = [];

                    step.querySelectorAll('input, select, textarea').forEach(function (field) {
                        if (field.disabled || field.type === 'hidden' || field.name === '_token') return;

                        var value = valueOf(field);
                        if (! value) return;

                        rows.push([labelFor(field), value]);
                    });

                    if (! rows.length) return;

                    var block = document.createElement('div');
                    block.className = 'freview-group';

                    var heading = document.createElement('h4');
                    heading.textContent = step.dataset.label || '';
                    block.appendChild(heading);

                    var list = document.createElement('dl');
                    list.className = 'freview-list';

                    rows.forEach(function (row) {
                        var dt = document.createElement('dt');
                        dt.textContent = row[0];
                        var dd = document.createElement('dd');
                        dd.textContent = row[1];
                        list.appendChild(dt);
                        list.appendChild(dd);
                    });

                    block.appendChild(list);
                    into.appendChild(block);
                });

                if (! into.children.length) {
                    into.innerHTML = '<p class="queue-meta">Nothing filled in yet — go back and complete the form.</p>';
                }
            }

            /* ---- append the review step ------------------------------ */
            var review = document.createElement('fieldset');
            review.className = 'fstep';
            review.dataset.label = REVIEW;
            review.innerHTML = '<p class="fstep-hint">Check everything below, then submit. '
                + 'Once submitted it goes to the first approver and you cannot edit it.</p>'
                + '<div class="freview"></div>';
            form.appendChild(review);
            steps.push(review);

            var reviewBody = review.querySelector('.freview');

            /* ---- progress rail --------------------------------------- */
            var rail = document.createElement('ol');
            rail.className = 'form-steps';
            rail.setAttribute('aria-label', 'Form progress');

            steps.forEach(function (step, i) {
                var li = document.createElement('li');
                li.className = 'form-step';
                li.innerHTML = '<span class="form-step-dot">' + (i + 1) + '</span>'
                    + '<span class="form-step-label"></span>';
                li.querySelector('.form-step-label').textContent = step.dataset.label || ('Step ' + (i + 1));
                rail.appendChild(li);
            });

            form.insertBefore(rail, form.firstChild);

            /* ---- navigation ------------------------------------------
               The form's own submit button is MOVED here rather than
               replaced, so each module keeps its own wording and the
               no-JS path still has exactly one submit.
               --------------------------------------------------------- */
            var submit = form.querySelector('button[type="submit"], input[type="submit"]');

            var nav = document.createElement('div');
            nav.className = 'fstep-nav';

            var back = document.createElement('button');
            back.type = 'button';
            back.className = 'btn-secondary';
            back.dataset.fstepBack = '';
            back.textContent = 'Back';

            var next = document.createElement('button');
            next.type = 'button';
            next.dataset.fstepNext = '';
            next.textContent = 'Continue';

            nav.appendChild(back);
            nav.appendChild(next);
            if (submit) nav.appendChild(submit);
            form.appendChild(nav);

            /* ---- showing one step at a time -------------------------- */
            var index = 0;

            function show(i, focus) {
                index = Math.max(0, Math.min(i, steps.length - 1));

                steps.forEach(function (step, n) {
                    step.hidden = n !== index;
                });

                Array.prototype.forEach.call(rail.children, function (li, n) {
                    li.classList.toggle('is-current', n === index);
                    li.classList.toggle('is-done', n < index);
                });

                var last = index === steps.length - 1;
                back.hidden = index === 0;
                next.hidden = last;
                if (submit) submit.hidden = ! last;

                if (last) buildReview(reviewBody);

                // Moving focus is what makes this usable from a keyboard --
                // without it, tabbing after Continue resumes from the button
                // rather than from the step that just appeared.
                if (focus) {
                    var target = steps[index].querySelector('input, select, textarea') || steps[index];
                    if (target.focus) target.focus({ preventScroll: true });
                    rail.scrollIntoView({ block: 'nearest' });
                }
            }

            /* ---- gating ----------------------------------------------
               Native constraint validation only, over this step's own
               controls. reportValidity() gives the browser's own message
               in the browser's own language, which is better than
               anything hand-rolled -- and the server re-checks all of it
               regardless, so this is purely there to save a round trip.
               --------------------------------------------------------- */
            function stepIsValid(step) {
                var fields = step.querySelectorAll('input, select, textarea');

                for (var i = 0; i < fields.length; i++) {
                    if (! fields[i].checkValidity()) {
                        fields[i].reportValidity();
                        return false;
                    }
                }

                return true;
            }

            next.addEventListener('click', function () {
                if (stepIsValid(steps[index])) show(index + 1, true);
            });

            back.addEventListener('click', function () {
                show(index - 1, true);
            });

            /* ---- open on the step the server complained about ---------
               A rejected submission comes back re-rendered with .is-invalid
               and .field-error in place. Landing on step 1 when the error
               is on step 3 is the classic way a wizard wastes someone's
               time, so find it and open there.
               --------------------------------------------------------- */
            var firstBad = steps.findIndex(function (step) {
                return step.querySelector('.is-invalid, .field-error');
            });

            show(firstBad > -1 ? firstBad : 0, false);
        })();
        </script>
    @endpush
@endonce
