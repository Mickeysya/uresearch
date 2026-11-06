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

    Everything else -- the layout, the step bar, Back/Continue, the
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
    no module has to write or maintain one. Its one line of prose says the
    form goes to an approver; a form that keeps a list or stores a file
    instead overrides it with data-stepper-review="..." on the <form>.
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

               ":disabled" rather than .disabled, because .disabled is the
               control's own attribute only -- a control switched off by a
               <fieldset disabled> around it reads back false, and the
               review would list values the browser is not going to submit.
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
                        if (field.matches(':disabled') || field.type === 'hidden' || field.name === '_token') return;

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
                    into.innerHTML = '<p class="queue-meta">Nothing filled in yet. Go back and complete the form.</p>';
                }
            }

            /* ---- append the review step ------------------------------
               The line above the review assumes the form files an
               application for approval, which most of them do. The ones
               that do not -- a list a module keeps for itself, an image
               uploaded once -- say so with data-stepper-review="..." on
               the <form>. The default is unchanged, so no existing form
               moves; textContent, so the override cannot inject markup.
               --------------------------------------------------------- */
            var review = document.createElement('fieldset');
            review.className = 'fstep';
            review.dataset.label = REVIEW;

            var reviewHint = document.createElement('p');
            reviewHint.className = 'fstep-hint';
            reviewHint.textContent = form.dataset.stepperReview
                || 'Check everything below, then submit. Once submitted it goes to '
                    + 'the first approver and you cannot edit it.';

            var reviewBody = document.createElement('div');
            reviewBody.className = 'freview';

            review.appendChild(reviewHint);
            review.appendChild(reviewBody);
            form.appendChild(review);
            steps.push(review);

            /* ---- layout ----------------------------------------------
               Step bar across the top of the card, the step underneath
               (2026-11-06). It used to be a 232px rail down the left --
               a second sidebar beside the app's own, which is also why the
               page was capped at 1080px and centred: the rail plus a 46rem
               field column left the rest of a full-width card empty.

               Each step is now two columns, the way the freed width is
               used: its name and opening hint on the left, its fields on
               the right (one column on a narrow card, by container query).
               Done in script so the markup contract stays `data-stepper` +
               .fstep -- no module's view changes.
               --------------------------------------------------------- */
            var card = form.closest('.card') || form.parentElement;
            card.classList.add('is-wizard');

            var heading = card.querySelector('h2');
            var divider = card.querySelector('.card-divider');
            if (divider) divider.remove();

            // A step's own leading .fstep-hint describes the whole step, so
            // it moves beside the step's name; everything else goes into the
            // fields column in its original order. The controls stay inside
            // their fieldset, so nothing about what is submitted changes.
            steps.forEach(function (step, i) {
                var aside = document.createElement('div');
                aside.className = 'fstep-aside';

                var title = document.createElement('h3');
                title.className = 'fstep-title';
                title.textContent = step.dataset.label || ('Step ' + (i + 1));
                aside.appendChild(title);

                var lead = step.firstElementChild;
                if (lead && lead.classList.contains('fstep-hint')) aside.appendChild(lead);

                var fields = document.createElement('div');
                fields.className = 'fstep-fields';
                while (step.firstChild) fields.appendChild(step.firstChild);

                step.appendChild(aside);
                step.appendChild(fields);
            });

            // A card that still carries its own h2 keeps it in a head band.
            // Most screens open with the page-header component instead, which
            // holds the title outside the card; the step bar says where you
            // are, so there is no separate "Step 1 of 3" counter any more.
            var head = null;
            if (heading) {
                head = document.createElement('div');
                head.className = 'wizard-head';
                head.appendChild(heading);
                card.insertBefore(head, card.firstChild);
            }

            // The step bar. Each step is a button, so a finished one can be
            // reached from the keyboard; unvisited ones are disabled. The
            // ol.form-steps > li.is-current contract is read by other
            // modules' scripts (Nureen's drafts) -- keep it.
            var band = document.createElement('div');
            band.className = 'wizard-steps';

            var rail = document.createElement('ol');
            rail.className = 'form-steps';
            rail.setAttribute('aria-label', 'Form progress');

            steps.forEach(function (step, i) {
                var li = document.createElement('li');
                li.className = 'form-step';

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'form-step-btn';
                // The number says how many steps are left at a glance; it
                // turns into a tick once the step is done (CSS).
                btn.innerHTML = '<span class="form-step-bar"><span class="form-step-fill"></span></span>'
                    + '<span class="form-step-label"><span class="form-step-num" aria-hidden="true"><span>' + (i + 1) + '</span></span>'
                    + '<span class="form-step-name"></span></span>';
                btn.querySelector('.form-step-name').textContent = step.dataset.label || ('Step ' + (i + 1));
                btn.setAttribute('aria-label', 'Step ' + (i + 1) + ' of ' + steps.length + ': ' + (step.dataset.label || ''));

                li.appendChild(btn);
                rail.appendChild(li);
            });

            // Below ~640px the labels cannot all fit, so they hide and this
            // one line says where you are instead.
            var summary = document.createElement('p');
            summary.className = 'form-steps-summary';
            summary.setAttribute('aria-live', 'polite');

            band.appendChild(rail);
            band.appendChild(summary);

            var pane = document.createElement('div');
            pane.className = 'wizard-pane';

            // Everything else the card held -- an intro paragraph, a
            // "you need a signature first" warning, a list of earlier
            // filings -- moves into the pane with the form, in its original
            // order.
            Array.prototype.slice.call(card.children).forEach(function (child) {
                if (child === head) return;
                pane.appendChild(child);
            });

            if (! pane.contains(form)) pane.appendChild(form);

            card.appendChild(band);
            card.appendChild(pane);

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
                    var btn = li.firstElementChild;
                    li.classList.toggle('is-current', n === index);
                    li.classList.toggle('is-done', n < index);
                    btn.disabled = n >= index;
                    if (n === index) btn.setAttribute('aria-current', 'step');
                    else btn.removeAttribute('aria-current');
                });

                var last = index === steps.length - 1;
                back.hidden = index === 0;
                next.hidden = last;
                if (submit) submit.hidden = ! last;

                summary.textContent = 'Step ' + (index + 1) + ' of ' + steps.length
                    + ' · ' + (steps[index].dataset.label || '');

                if (last) buildReview(reviewBody);

                // Moving focus is what makes this usable from a keyboard --
                // without it, tabbing after Continue resumes from the button
                // rather than from the step that just appeared.
                if (focus) {
                    var first = steps[index].querySelector('input, select, textarea') || steps[index];
                    if (first.focus) first.focus({ preventScroll: true });
                    band.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
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
                if (! stepIsValid(steps[index])) return;
                // The server's complaint about this step has been dealt with
                // as far as the browser can tell, so it stops showing red.
                rail.children[index].classList.remove('is-error');
                show(index + 1, true);
            });

            back.addEventListener('click', function () {
                show(index - 1, true);
            });

            // A finished step is a button in the bar -- the same shortcut the
            // review's Edit links give. Unvisited ones are disabled in show().
            Array.prototype.forEach.call(rail.children, function (li, n) {
                li.firstElementChild.addEventListener('click', function () {
                    if (n < index) show(n, true);
                });
            });

            /* ---- open on the step the server complained about ---------
               A rejected submission comes back re-rendered with .is-invalid
               and .field-error in place. Landing on step 1 when the error
               is on step 3 is the classic way a wizard wastes someone's
               time, so find it and open there.
               --------------------------------------------------------- */
            var firstBad = -1;
            steps.forEach(function (step, n) {
                // Marked on the bar too (Carbon's error state), so a student
                // sent back can see every step that needs fixing, not just
                // the one it opened on.
                if (! step.querySelector('.is-invalid, .field-error')) return;
                rail.children[n].classList.add('is-error');
                if (firstBad < 0) firstBad = n;
            });

            show(firstBad > -1 ? firstBad : 0, false);
        })();
        </script>
    @endpush
@endonce
