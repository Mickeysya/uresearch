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

    Everything else -- the layout, the progress rail, Back/Continue, the
    review step, moving your submit button and your heading -- is built here.

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
    no module has to write or maintain one.
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
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

                steps.forEach(function (step, stepIndex) {
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

                    // An edit link per group, so a wrong answer on step 1 does
                    // not mean clicking Back three times to reach it.
                    var edit = document.createElement('button');
                    edit.type = 'button';
                    edit.className = 'freview-edit';
                    edit.textContent = 'Edit';
                    edit.addEventListener('click', function () { show(stepIndex, true); });
                    heading.appendChild(edit);

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

            /* ---- layout ----------------------------------------------
               The form starts life inside a .card with the page heading
               above it. That is the right shape for a short form and the
               wrong one for a wizard: the heading repeats what the rail
               already says, and a 680px card wastes most of a desktop
               screen on a form with two columns' worth of fields.

               So the card is re-cast here: the heading moves into a band
               at the top with the step counter beside it, the rail
               becomes a left-hand column, and the step content gets the
               rest. Done in script rather than in nine Blade files
               because the markup contract stays `data-stepper` + .fstep
               -- a module that adopts the wizard gets the new layout
               without editing its view.
               --------------------------------------------------------- */
            var card = form.closest('.card') || form.parentElement;
            card.classList.add('is-wizard');

            var container = card.closest('.card-container-inline');
            if (container) container.classList.add('is-wizard-container');

            var heading = card.querySelector('h2');
            var divider = card.querySelector('.card-divider');
            if (divider) divider.remove();

            var head = document.createElement('div');
            head.className = 'wizard-head';

            var counter = document.createElement('p');
            counter.className = 'wizard-counter';

            if (heading) {
                head.appendChild(heading);
            }
            head.appendChild(counter);
            card.insertBefore(head, card.firstChild);

            // Rail and content live side by side below the head.
            var body = document.createElement('div');
            body.className = 'wizard-body';
            card.appendChild(body);

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

            var pane = document.createElement('div');
            pane.className = 'wizard-pane';

            body.appendChild(rail);
            body.appendChild(pane);
            pane.appendChild(form);

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
                var target = Math.max(0, Math.min(i, steps.length - 1));
                var forward = target > index;
                index = target;

                steps.forEach(function (step, n) {
                    step.hidden = n !== index;
                });

                // Re-triggering the animation needs the class off, a reflow,
                // then the class on -- otherwise the browser sees no change
                // and skips it on every step after the first.
                if (! reduceMotion) {
                    var active = steps[index];
                    active.classList.remove('is-entering-fwd', 'is-entering-back');
                    void active.offsetWidth;
                    active.classList.add(forward ? 'is-entering-fwd' : 'is-entering-back');
                }

                Array.prototype.forEach.call(rail.children, function (li, n) {
                    li.classList.toggle('is-current', n === index);
                    li.classList.toggle('is-done', n < index);
                });

                var last = index === steps.length - 1;
                back.hidden = index === 0;
                next.hidden = last;
                if (submit) submit.hidden = ! last;

                counter.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;

                if (last) buildReview(reviewBody);

                // Moving focus is what makes this usable from a keyboard --
                // without it, tabbing after Continue resumes from the button
                // rather than from the step that just appeared.
                if (focus) {
                    var first = steps[index].querySelector('input, select, textarea') || steps[index];
                    if (first.focus) first.focus({ preventScroll: true });
                    card.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
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

            // A finished step is clickable in the rail -- the same shortcut the
            // review's Edit links give, for people who navigate by the rail.
            Array.prototype.forEach.call(rail.children, function (li, n) {
                li.addEventListener('click', function () {
                    if (n < index) show(n, true);
                });
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
