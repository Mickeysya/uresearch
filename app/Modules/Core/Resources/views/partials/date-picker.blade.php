{{--
    A calendar panel for every <input type="date"> in the portal.

    WHY THIS EXISTS AT ALL. TODO.md argued against a custom date picker, and
    that argument still holds for the usual version of this: a widget that
    REPLACES the native input, then has to re-implement typing, locale, mobile
    and screen-reader support, and lands somewhere worse than what the browser
    already gives you.

    This is the other kind. The native <input type="date"> stays exactly where
    it is and stays the source of truth -- it holds the value, it is what the
    form posts, it is what validation reads, and typing into it still works.
    All that is added is a panel drawn over it for people using a mouse, and
    nothing here is required to fill the field in.

    So the things that argument was protecting are all still true:

      - JavaScript off, or this script broken: the native input is untouched
        and behaves exactly as it did before.
      - Touch and coarse pointers: skipped entirely. The OS date wheel beats
        any panel on a phone, so it is left alone.
      - Keyboard: the input's own typing behaviour is never intercepted. The
        panel adds arrows/Enter/Escape on top, and is reachable but skippable.
      - Locale: the panel writes into the input as ISO and lets the browser
        render it in the user's own format, same as before.

    WHAT IT FIXES. The native indicator is a 12px platform glyph that is a
    different shape on every browser, cannot be styled, and opens a panel that
    matches the OS rather than the portal. On the dark theme that panel arrives
    as a bright rectangle. This draws one that belongs to the page.
--}}
@once
    @push('scripts')
        <script @cspNonce>
        (function () {
            // Coarse pointer -- a phone or tablet. The native wheel is better
            // than anything here; leave it completely alone.
            if (window.matchMedia('(pointer: coarse)').matches) return;

            var MS_DAY = 86400000;
            var DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
            var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

            /* All arithmetic is in UTC. A date input holds a calendar date with
               no time and no zone; going through local time is how a picker
               ends up one day out for anyone west of the server. */
            function utc(y, m, d) { return new Date(Date.UTC(y, m, d)); }

            function parseISO(value) {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
                if (! m) return null;
                var d = utc(+m[1], +m[2] - 1, +m[3]);
                return isNaN(d.getTime()) ? null : d;
            }

            function toISO(d) {
                return d.getUTCFullYear() + '-'
                    + String(d.getUTCMonth() + 1).padStart(2, '0') + '-'
                    + String(d.getUTCDate()).padStart(2, '0');
            }

            function today() {
                var n = new Date();
                return utc(n.getFullYear(), n.getMonth(), n.getDate());
            }

            /* Monday-first: the weekday header reads Mo..Su, so the offset is
               how many days back from this date the containing week began. */
            function mondayIndex(d) { return (d.getUTCDay() + 6) % 7; }

            function Picker(input) {
                var min = parseISO(input.getAttribute('min'));
                var max = parseISO(input.getAttribute('max'));
                var cursor = parseISO(input.value) || today();
                var open = false;
                var panel, grid, monthSelect, yearSelect, wrap;

                /**
                 * Years offered in the dropdown.
                 *
                 * Driven by the field's own min/max where it has them, which
                 * is what makes one picker serve both ends of the portal: a
                 * travel date carries min=today and offers forward years, a
                 * candidature start carries max=today and offers back ones.
                 * With neither, ten years either side covers every remaining
                 * field without an unscrollable list.
                 */
                function yearRange() {
                    var now = today().getUTCFullYear();
                    var from = min ? min.getUTCFullYear() : now - 10;
                    var to = max ? max.getUTCFullYear() : now + 10;

                    // A value already in the field must be reachable even if it
                    // sits outside the bounds -- otherwise opening the panel on
                    // an existing date silently shows the wrong year.
                    var current = parseISO(input.value);
                    if (current) {
                        from = Math.min(from, current.getUTCFullYear());
                        to = Math.max(to, current.getUTCFullYear());
                    }

                    var years = [];
                    for (var y = from; y <= to; y++) years.push(y);
                    return years;
                }

                function inRange(d) {
                    if (min && d < min) return false;
                    if (max && d > max) return false;
                    return true;
                }

                function build() {
                    panel = document.createElement('div');
                    panel.className = 'dp-panel';
                    panel.setAttribute('role', 'dialog');
                    panel.setAttribute('aria-label', 'Choose a date');
                    panel.hidden = true;

                    var head = document.createElement('div');
                    head.className = 'dp-head';

                    var prev = button('‹', 'Previous month', function () { shiftMonth(-1); });
                    var next = button('›', 'Next month', function () { shiftMonth(1); });
                    prev.className = 'dp-nav';
                    next.className = 'dp-nav';

                    /* Month and year are SELECTS, not a label with arrows.
                       Most dates in this portal are weeks away and the arrows
                       reach them fine, but a candidature start date is years
                       back -- 36 clicks on the arrow to register a part-time
                       PhD is not a date picker, it is a penalty. A select
                       reaches any month in the range in one gesture, is
                       keyboard- and screen-reader-native, and is already
                       styled by the portal's own select rules. */
                    monthSelect = document.createElement('select');
                    monthSelect.className = 'dp-select dp-select-month';
                    monthSelect.setAttribute('aria-label', 'Month');

                    MONTHS.forEach(function (name, i) {
                        var opt = document.createElement('option');
                        opt.value = i;
                        opt.textContent = name;
                        monthSelect.appendChild(opt);
                    });

                    yearSelect = document.createElement('select');
                    yearSelect.className = 'dp-select dp-select-year';
                    yearSelect.setAttribute('aria-label', 'Year');

                    yearRange().forEach(function (y) {
                        var opt = document.createElement('option');
                        opt.value = y;
                        opt.textContent = y;
                        yearSelect.appendChild(opt);
                    });

                    function jump() {
                        var y = +yearSelect.value;
                        var m = +monthSelect.value;
                        // Clamp the day so 31 Jan -> Feb lands on the 28th/29th
                        // rather than rolling into March.
                        var lastDay = utc(y, m + 1, 0).getUTCDate();
                        cursor = utc(y, m, Math.min(cursor.getUTCDate(), lastDay));
                        render();
                    }

                    monthSelect.addEventListener('change', jump);
                    yearSelect.addEventListener('change', jump);

                    var pickers = document.createElement('div');
                    pickers.className = 'dp-month';
                    pickers.appendChild(monthSelect);
                    pickers.appendChild(yearSelect);

                    head.appendChild(prev);
                    head.appendChild(pickers);
                    head.appendChild(next);
                    panel.appendChild(head);

                    var week = document.createElement('div');
                    week.className = 'dp-week';
                    DAYS.forEach(function (d) {
                        var s = document.createElement('span');
                        s.textContent = d;
                        week.appendChild(s);
                    });
                    panel.appendChild(week);

                    grid = document.createElement('div');
                    grid.className = 'dp-grid';
                    panel.appendChild(grid);

                    var foot = document.createElement('div');
                    foot.className = 'dp-foot';

                    var todayBtn = button('Today', 'Jump to today', function () {
                        var t = today();
                        if (! inRange(t)) return;
                        commit(t);
                    });
                    var clearBtn = button('Clear', 'Clear the date', function () {
                        input.value = '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        close(true);
                    });
                    todayBtn.className = 'dp-text-btn';
                    clearBtn.className = 'dp-text-btn';

                    foot.appendChild(todayBtn);
                    foot.appendChild(clearBtn);
                    panel.appendChild(foot);

                    wrap.appendChild(panel);
                }

                function button(label, title, onClick) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.title = title;
                    b.setAttribute('aria-label', title);
                    b.addEventListener('click', function (e) {
                        e.preventDefault();
                        onClick();
                    });
                    return b;
                }

                function shiftMonth(by) {
                    // Clamp to the last day of the target month so 31 Jan + 1
                    // lands on 28/29 Feb rather than rolling into March.
                    var target = cursor.getUTCMonth() + by;
                    var lastDay = utc(cursor.getUTCFullYear(), target + 1, 0).getUTCDate();
                    cursor = utc(cursor.getUTCFullYear(), target, Math.min(cursor.getUTCDate(), lastDay));
                    render();
                }

                function commit(d) {
                    input.value = toISO(d);
                    cursor = d;
                    // Both events: `input` for anything listening live (the RPD
                    // deadline preview), `change` for everything else.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    close(true);
                }

                function render() {
                    monthSelect.value = cursor.getUTCMonth();
                    yearSelect.value = cursor.getUTCFullYear();
                    grid.innerHTML = '';

                    var first = utc(cursor.getUTCFullYear(), cursor.getUTCMonth(), 1);
                    var start = new Date(first.getTime() - mondayIndex(first) * MS_DAY);
                    var selected = parseISO(input.value);
                    var now = today();

                    // Six rows always, so the panel does not change height
                    // between months and shove the page around underneath it.
                    for (var i = 0; i < 42; i++) {
                        var day = new Date(start.getTime() + i * MS_DAY);
                        var cell = document.createElement('button');
                        cell.type = 'button';
                        cell.className = 'dp-day';
                        cell.textContent = day.getUTCDate();
                        cell.dataset.iso = toISO(day);

                        if (day.getUTCMonth() !== cursor.getUTCMonth()) cell.classList.add('is-outside');
                        if (selected && day.getTime() === selected.getTime()) cell.classList.add('is-selected');
                        if (day.getTime() === now.getTime()) cell.classList.add('is-today');

                        if (! inRange(day)) {
                            cell.disabled = true;
                        } else {
                            (function (d) {
                                cell.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    commit(d);
                                });
                            })(day);
                        }

                        grid.appendChild(cell);
                    }
                }

                function position() {
                    // Flip above the field when there is not room below it.
                    var box = input.getBoundingClientRect();
                    var room = window.innerHeight - box.bottom;
                    panel.classList.toggle('is-above', room < 340 && box.top > 340);
                }

                function show() {
                    if (open) return;
                    cursor = parseISO(input.value) || today();
                    render();
                    panel.hidden = false;
                    open = true;
                    position();
                }

                function close(focusInput) {
                    if (! open) return;
                    panel.hidden = true;
                    open = false;
                    if (focusInput) input.focus();
                }

                /* ---- wiring ---- */

                // The input is wrapped so the panel can be positioned against
                // it without depending on whatever the page's layout is.
                wrap = document.createElement('div');
                wrap.className = 'dp-wrap';
                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);

                build();

                var trigger = button('', 'Open the calendar', function () {
                    open ? close(true) : show();
                });
                trigger.className = 'dp-trigger';
                trigger.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                    + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                    + '<rect x="3" y="4" width="18" height="18" rx="2"/>'
                    + '<path d="M16 2v4M8 2v4M3 10h18"/></svg>';
                // Not a tab stop: the input beside it already takes focus and
                // accepts typing, so this would only be a second stop that
                // does nothing new for a keyboard user.
                trigger.tabIndex = -1;
                wrap.appendChild(trigger);

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && open) { e.preventDefault(); close(true); return; }

                    if (! open) {
                        if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); show(); }
                        return;
                    }

                    var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];

                    if (step) {
                        e.preventDefault();
                        var moved = new Date(cursor.getTime() + step * MS_DAY);
                        if (inRange(moved)) { cursor = moved; render(); }
                        return;
                    }

                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (inRange(cursor)) commit(cursor);
                    }
                });

                // Clicking away closes it. Checked against the wrapper so a
                // click inside the panel itself does not count as away.
                document.addEventListener('click', function (e) {
                    if (open && ! wrap.contains(e.target)) close(false);
                });

                // A step change in the wizard hides the field without a click
                // anywhere, which would otherwise strand an open panel.
                window.addEventListener('scroll', function () { if (open) position(); }, true);
            }

            document.querySelectorAll('input[type="date"]').forEach(function (input) {
                // data-no-picker opts a field out, for anything that wants the
                // plain native control back.
                if (! input.hasAttribute('data-no-picker')) new Picker(input);
            });
        })();
        </script>
    @endpush
@endonce
