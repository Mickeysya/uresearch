/* Study Candidacy pages. No inline handlers: everything is wired here from data attributes and ids.
   - assistant placeholder: opens a static panel, makes NO request and stores nothing
   - live updates: polls a tiny version endpoint every 15 s while the tab is visible, swaps #wca-live-region in place
   - publication rows, RCS fields, modals */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // ---- assistant placeholder
    var toggle = $('#wca-assistant-toggle'), panel = $('#wca-assistant-panel');
    if (toggle && panel) {
        var setOpen = function (open) { panel.hidden = !open; toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); };
        toggle.addEventListener('click', function () { setOpen(panel.hidden); });
        var close = $('#wca-assistant-close');
        if (close) { close.addEventListener('click', function () { setOpen(false); }); }
    }

    // ---- publications: add and remove rows
    var pubs = $('#wca-pubs'), tpl = $('#wca-pub-template');
    if (pubs && tpl) {
        var next = $all('.wca-pub-row', pubs).length;
        $('#wca-pub-add').addEventListener('click', function () {
            if ($all('.wca-pub-row', pubs).length >= 20) { return; }
            var html = tpl.innerHTML.replace(/__i__/g, String(next++));
            pubs.insertAdjacentHTML('beforeend', html);
        });
        pubs.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pub-remove]');
            if (btn) { var row = btn.closest('.wca-pub-row'); if (row) { row.parentNode.removeChild(row); } }
        });
    }

    // ---- RCS: show the date that matches the status
    var rcs = $all('input[name="rcs_status"]');
    if (rcs.length) {
        var syncRcs = function () {
            var v = ($('input[name="rcs_status"]:checked') || {}).value;
            $all('[data-rcs]').forEach(function (el) { el.classList.toggle('wca-hidden', el.getAttribute('data-rcs') !== v); });
        };
        rcs.forEach(function (r) { r.addEventListener('change', syncRcs); });
        syncRcs();
    }

    // ---- department -> supervisor list follows the department (the page reloads with ?department=)
    var dep = $('#wca-department');
    if (dep) {
        dep.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('department', dep.value);
            window.location.href = url.toString();
        });
    }

    // ---- modals (a decision always asks in a modal, never with a browser confirm)
    $all('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var m = document.getElementById(btn.getAttribute('data-modal-open'));
            if (m) { m.hidden = false; document.documentElement.setAttribute('data-wca-modal', '1'); var f = $('textarea, input[type="checkbox"]', m); if (f) { f.focus(); } }
        });
    });
    function closeModals() { $all('.wca-modal').forEach(function (m) { m.hidden = true; }); document.documentElement.removeAttribute('data-wca-modal'); }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-modal-close]') || (e.target.classList && e.target.classList.contains('wca-modal'))) { closeModals(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModals(); } });

    // ---- other-needs-a-remark hint
    $all('.wca-modal form').forEach(function (form) {
        var other = $('input[value="other"]', form), remark = $('textarea[name="remark"]', form), need = $('[data-remark-need]', form);
        if (other && remark && need) {
            var sync = function () { need.classList.toggle('wca-hidden', !other.checked); };
            other.addEventListener('change', sync); sync();
        }
    });

    // ---- live updates
    var cfg = $('#wca-live');
    var region = $('#wca-live-region');
    if (cfg && region) {
        var version = cfg.getAttribute('data-version'), busy = false, timer = null;
        var interval = parseInt(cfg.getAttribute('data-interval') || '15000', 10);
        var status = $('#wca-live-status');
        var safe = function () {
            var a = document.activeElement;
            var typing = a && region.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
            return !typing && !document.documentElement.hasAttribute('data-wca-modal');
        };
        var check = function () {
            if (document.hidden || busy) { return; }
            busy = true;
            fetch(cfg.getAttribute('data-version-url'), { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || j.version === version) { return null; }
                    if (!safe()) { return null; }
                    return fetch(cfg.getAttribute('data-region-url'), { credentials: 'same-origin', cache: 'no-store' })
                        .then(function (r) { return r.ok ? r.text() : null; })
                        .then(function (html) {
                            if (html === null || !safe()) { return; }
                            region.innerHTML = html; version = j.version;
                            if (status) { status.textContent = 'Updated just now.'; }
                        });
                })
                .catch(function () {})
                .then(function () { busy = false; });
        };
        var start = function () { if (!timer) { timer = window.setInterval(check, interval); } };
        var stop = function () { if (timer) { window.clearInterval(timer); timer = null; } };
        document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { check(); start(); } });
        if (!document.hidden) { start(); }
    }
})();
