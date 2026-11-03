/*
 * "Suggested examiners" on the examiner nomination form (Hani's module).
 *
 * Asks /examiner-nomination/suggestions whenever the candidate or the thesis
 * title changes, and lists the best matches under each panel step with the
 * reason for each: the score, the matched terms, how long since the last
 * examination, and any conflict-of-interest flag. "Use as main" / "Use as
 * backup" fills the seat; the dropdowns stay free, so any eligible examiner
 * can still be chosen by hand.
 *
 * A file, not an inline block, because script-src allows only 'self' and the
 * per-request nonce. Every value from the server is written with
 * textContent, never innerHTML, so nothing in an examiner record can become
 * markup.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-suggest-url]');
    if (!form) return;

    var url = form.getAttribute('data-suggest-url');
    var candidate = form.querySelector('[name="student_id"]');
    var title = form.querySelector('[name="thesis_title"]');
    var panels = {
        internal: form.querySelector('[data-suggestions="internal"]'),
        external: form.querySelector('[data-suggestions="external"]')
    };
    var seats = {
        internal: { main: 'internal_main_id', backup: 'internal_backup_id' },
        external: { main: 'external_main_id', backup: 'external_backup_id' }
    };
    var timer = null;
    var latest = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function message(panel, text) {
        panel.replaceChildren(el('p', 'suggest-empty', text));
    }

    function fill(field, id) {
        var select = form.querySelector('[name="' + field + '"]');
        if (!select) return false;
        var option = select.querySelector('option[value="' + String(id) + '"]');
        if (!option || option.disabled) return false;
        select.value = String(id);
        select.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function render(type, items, query) {
        var panel = panels[type];
        if (!panel) return;

        if (!items.length) {
            message(panel, query && query.source === 'nothing yet'
                ? 'Enter the thesis title to see suggestions.'
                : 'No eligible examiner\'s expertise matches this thesis yet. Any eligible examiner below can still be chosen.');
            return;
        }

        var list = el('ol', 'suggest-list');

        items.forEach(function (item) {
            var li = el('li', 'suggest-item');
            var head = el('div', 'suggest-head');
            head.appendChild(el('strong', null, item.name));
            head.appendChild(el('span', 'suggest-score', item.score + '% match'));
            li.appendChild(head);
            if (item.where) li.appendChild(el('div', 'suggest-where', item.where));
            if (item.matched && item.matched.length) {
                li.appendChild(el('div', 'suggest-matched', 'Matched on: ' + item.matched.join(', ')));
            }
            if (item.reasons && item.reasons.length) {
                li.appendChild(el('div', 'suggest-reasons', item.reasons.join(' · ')));
            }
            (item.flags || []).forEach(function (flag) {
                li.appendChild(el('div', 'suggest-flag is-' + flag.level,
                    (flag.level === 'block' ? 'Conflict: ' : 'Check: ') + flag.message));
            });
            if (item.needs_email) li.appendChild(el('div', 'suggest-flag is-warning', 'Email needed before letters can be sent.'));

            var blocked = (item.flags || []).some(function (f) { return f.level === 'block'; });
            var actions = el('div', 'suggest-actions');
            ['main', 'backup'].forEach(function (seat) {
                var button = el('button', 'btn-secondary', seat === 'main' ? 'Use as main' : 'Use as backup');
                button.type = 'button';
                button.disabled = blocked;
                button.addEventListener('click', function () {
                    if (!fill(seats[type][seat], item.id)) {
                        button.textContent = 'Not selectable';
                    }
                });
                actions.appendChild(button);
            });
            li.appendChild(actions);
            list.appendChild(li);
        });

        panel.replaceChildren(list);
    }

    function seatFields() {
        return [seats.internal.main, seats.internal.backup, seats.external.main, seats.external.backup];
    }

    // Flags for the examiners actually chosen in the dropdowns, so a
    // hand-picked supervisor is caught here and not only on submit.
    function renderChecks(checks) {
        seatFields().forEach(function (field) {
            var slot = form.querySelector('[data-coi-for="' + field + '"]');
            var select = form.querySelector('[name="' + field + '"]');
            if (!slot) return;
            var flags = (select && select.value && checks[select.value]) || [];
            slot.replaceChildren();
            flags.forEach(function (flag) {
                slot.appendChild(el('div', 'suggest-flag is-' + flag.level,
                    (flag.level === 'block' ? 'Conflict: ' : 'Check: ') + flag.message));
            });
        });
    }

    function load() {
        if (!candidate || !candidate.value) {
            Object.keys(panels).forEach(function (type) {
                if (panels[type]) message(panels[type], 'Choose the candidate to see suggested examiners.');
            });
            return;
        }

        var ticket = ++latest;
        var params = new URLSearchParams({ student_id: candidate.value, title: title ? title.value : '' });
        seatFields().forEach(function (field) {
            var select = form.querySelector('[name="' + field + '"]');
            if (select && select.value) params.append('check[]', select.value);
        });

        Object.keys(panels).forEach(function (type) {
            if (panels[type]) message(panels[type], 'Finding examiners whose expertise fits…');
        });

        fetch(url + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) throw new Error(String(response.status));
                return response.json();
            })
            .then(function (data) {
                if (ticket !== latest) return; // a newer request has been sent
                render('internal', data.internal || [], data.query);
                render('external', data.external || [], data.query);
                renderChecks(data.checks || {});
            })
            .catch(function () {
                if (ticket !== latest) return;
                Object.keys(panels).forEach(function (type) {
                    if (panels[type]) message(panels[type], 'Suggestions are unavailable right now. Choose from the list below.');
                });
            });
    }

    function soon() {
        clearTimeout(timer);
        timer = setTimeout(load, 400);
    }

    if (candidate) candidate.addEventListener('change', load);
    seatFields().forEach(function (field) {
        var select = form.querySelector('[name="' + field + '"]');
        if (select) select.addEventListener('change', load);
    });
    if (title) title.addEventListener('input', soon);
    load();
})();
