/* Study Candidacy pages. No inline handlers: everything is wired here from data attributes and ids.
   - live updates: polls a tiny version endpoint every 15 s while the tab is visible, swaps #wca-live-region in place
   - publication rows, RCS fields, modals */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // ---- the tab title (the shared layout writes a fixed <title>, so a page may name itself)
    var named = $('[data-doc-title]');
    if (named) { document.title = named.getAttribute('data-doc-title'); }

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

    // ---- modals (a decision always asks in a modal, never with a browser confirm). Delegated, so a live refresh keeps them working.
    // A "confirm" dialog (the Dean's second, final dialog) sits ABOVE its modal: Esc, clicking outside and Discard close only it.
    function openConfirms() { return $all('.wca-confirm').filter(function (m) { return !m.hidden; }); }
    function closeConfirms() { openConfirms().forEach(function (m) { m.hidden = true; }); }
    function closeModals() { closeConfirms(); $all('.wca-modal').forEach(function (m) { m.hidden = true; }); document.documentElement.removeAttribute('data-wca-modal'); }
    function problem(form, text) { var p = $('.wca-error[id$="-problem"]', form); if (p) { p.textContent = text || ''; p.classList.toggle('wca-hidden', !text); } }
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) {
            var m = document.getElementById(opener.getAttribute('data-modal-open'));
            if (m) { m.hidden = false; document.documentElement.setAttribute('data-wca-modal', '1'); }
            return;
        }
        var ask = e.target.closest('[data-confirm-open]');
        if (ask) {
            var form = ask.closest('form'), dlg = document.getElementById(ask.getAttribute('data-confirm-open'));
            var ticked = $all('input[name="reasons[]"]:checked', form), remark = ($('textarea[name="remark"]', form) || {}).value || '';
            if (!ticked.length) { problem(form, 'Pick at least one reason from the list.'); return; }
            if (ticked.some(function (c) { return c.value === 'other'; }) && !remark.trim()) { problem(form, 'A remark is required when the reason is "Other".'); return; }
            problem(form, '');
            var list = $('#wca-confirm-reasons', dlg); list.textContent = '';
            ticked.forEach(function (c) { var li = document.createElement('li'); li.textContent = c.parentNode.textContent.trim(); list.appendChild(li); });
            $('#wca-confirm-remark', dlg).textContent = remark.trim(); $('#wca-confirm-remark-wrap', dlg).classList.toggle('wca-hidden', !remark.trim());
            var final = $('#wca-confirm-final', dlg); final.disabled = false; final.removeAttribute('data-sent');
            dlg.hidden = false; var discard = $('#wca-confirm-discard', dlg); if (discard) { discard.focus(); }
            return;
        }
        var submit = e.target.closest('[data-confirm-submit]');
        if (submit) {
            if (submit.getAttribute('data-sent')) { return; } // a second click does nothing
            submit.setAttribute('data-sent', '1'); submit.disabled = true;
            var f = document.getElementById(submit.getAttribute('data-confirm-submit')); if (f) { f.submit(); }
            return;
        }
        if (e.target.closest('[data-confirm-close]')) { closeConfirms(); return; }
        if (e.target.classList && e.target.classList.contains('wca-confirm')) { closeConfirms(); return; }
        if (e.target.closest('[data-modal-close]') || (e.target.classList && e.target.classList.contains('wca-modal'))) { closeModals(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { if (openConfirms().length) { closeConfirms(); } else { closeModals(); } } });
    document.addEventListener('change', function (e) {
        var other = e.target.closest('input[value="other"]');
        if (other) { var need = $('[data-remark-need]', other.closest('form')); if (need) { need.classList.toggle('wca-hidden', !other.checked); } }
    });

    // ---- signature: preview the chosen file before saving
    var sigFile = $('#wca-sig-file');
    if (sigFile) {
        sigFile.addEventListener('change', function () {
            var f = sigFile.files && sigFile.files[0], box = $('#wca-sig-new'), img = $('#wca-sig-new-img');
            if (!f || !/^image\/(png|jpeg)$/.test(f.type)) { box.classList.add('wca-hidden'); return; }
            var reader = new FileReader(); reader.onload = function () { img.src = reader.result; box.classList.remove('wca-hidden'); }; reader.readAsDataURL(f);
        });
    }

    // ---- live updates (same design as Workstation): every 15 s while the tab is visible, at once when it becomes visible again,
    // the "Pause updates" control, and the region is replaced in place with the scroll position kept.
    var cfg = $('#wca-live');
    var region = $('#wca-live-region');
    if (cfg && region) {
        var version = cfg.getAttribute('data-version'), busy = false, timer = null, paused = false;
        var interval = parseInt(cfg.getAttribute('data-interval') || '15000', 10);
        var status = $('#wca-live-status'), dot = $('#wca-live-dot'), pauseBtn = $('#wca-live-pause');
        var clock = function () { return new Date().toLocaleTimeString('en-GB', { timeZone: 'Asia/Kuala_Lumpur', hour12: false }); };
        var safe = function () {
            var a = document.activeElement;
            var typing = a && region.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
            return !typing && !document.documentElement.hasAttribute('data-wca-modal');
        };
        var stamp = function () { if (status) { status.textContent = 'Live \u00b7 updated ' + clock(); } };
        var applyStats = function (j) {
            if (!j || !j.stats) { return; }
            Object.keys(j.stats).forEach(function (k) {
                $all('[data-live-stat="' + k + '"], [data-live-stat="tab_' + k + '"]', region).forEach(function (el) { el.textContent = String(j.stats[k]); });
            });
        };
        var json = function (url) { return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }); };
        var check = function () {
            if (document.hidden || busy || paused) { return; }
            busy = true;
            json(cfg.getAttribute('data-version-url'))
                .then(function (j) {
                    if (!j) { return null; }
                    if (j.version === version) { stamp(); return null; }
                    if (!safe()) { return null; }
                    var statsUrl = cfg.getAttribute('data-stats-url');
                    return (statsUrl ? json(statsUrl).then(applyStats) : Promise.resolve())
                        .then(function () { return fetch(cfg.getAttribute('data-region-url'), { credentials: 'same-origin', cache: 'no-store' }); })
                        .then(function (r) { return r.ok ? r.text() : null; })
                        .then(function (html) {
                            if (html === null || !safe()) { return; }
                            var y = window.scrollY;
                            region.innerHTML = html; version = j.version;
                            window.scrollTo(0, y); stamp();
                        });
                })
                .catch(function () {})
                .then(function () { busy = false; });
        };
        var start = function () { if (!timer) { timer = window.setInterval(check, interval); } };
        var stop = function () { if (timer) { window.clearInterval(timer); timer = null; } };
        document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else if (!paused) { check(); start(); } });
        if (pauseBtn) {
            pauseBtn.addEventListener('click', function () {
                paused = !paused;
                pauseBtn.textContent = paused ? 'Resume updates' : 'Pause updates';
                if (dot) { dot.classList.toggle('is-green', !paused); dot.classList.toggle('is-slate', paused); }
                if (paused) { stop(); if (status) { status.textContent = 'Paused'; } } else { check(); start(); }
            });
        }
        if (!document.hidden) { start(); }
    }
})();
