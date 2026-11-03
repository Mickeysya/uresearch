/*
 * Workstation live updates (Chloe module). Plain polling with fetch --
 * no WebSockets, no external service, no library.
 *
 * window.ChloeLive.createPoller() is the one polling loop, shared by the
 * Workstation pages (below) and the Insights page (chloe-workstation-
 * insights.js):
 *   - polls a cheap version endpoint every `interval` ms, but ONLY while
 *     the tab is visible and the window has focus;
 *   - hidden/unfocused -> no requests at all; becoming visible/focused
 *     again checks immediately;
 *   - an error backs off exponentially (interval * 2^n, capped at 2 min);
 *   - three 401/403 responses in a row stop it for good;
 *   - pageshow with event.persisted (back/forward cache) and a tab that
 *     becomes visible after more than 15 seconds hidden both re-check
 *     straight away, so Back never leaves a stale number on screen.
 *
 * The Workstation pages themselves are configured by #wsl-config
 * (data attributes -- no inline script, no inline handlers):
 *   data-kind          "cgs" | "student"
 *   data-version       the data version when the page was rendered
 *   data-version-url   GET -> {version, server_time}
 *   data-stats-url     (cgs) GET -> {version, stats, rooms}
 *   data-interval      polling interval in ms
 * Elements updated in place:
 *   [data-live-stat="key"]                       stats bar numbers
 *   [data-live-room="id"] [data-live-count="k"]  room card counts
 * Server-rendered lists: the single [data-live-region] is re-requested
 * from the same URL and only its content is swapped.
 */
(function () {
    'use strict';

    var REDUCED_MOTION = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var MAX_BACKOFF_MS = 2 * 60 * 1000;
    var LONG_HIDDEN_MS = 15 * 1000;

    function isVisible() {
        return document.visibilityState === 'visible';
    }

    function hasFocus() {
        return typeof document.hasFocus === 'function' ? document.hasFocus() : true;
    }

    // =====================================================================
    // THE POLLER
    // =====================================================================
    /**
     * opts: versionUrl, interval, version (initial), onChange(json),
     *       onTick(json) (every successful poll), onStop()
     * returns {checkNow, pause, resume, isPaused, stop, state}
     */
    function createPoller(opts) {
        var state = {
            version: opts.version || null,
            failures: 0,
            authFailures: 0,
            stopped: false,
            userPaused: false,
            inFlight: false,
            hiddenSince: null,
            requests: 0,
        };
        var timer = null;

        function canPoll() {
            return !state.stopped && !state.userPaused && isVisible() && hasFocus();
        }

        function nextDelay() {
            return state.failures > 0
                ? Math.min(MAX_BACKOFF_MS, opts.interval * Math.pow(2, state.failures))
                : opts.interval;
        }

        function schedule() {
            clearTimeout(timer);
            timer = null;
            if (!canPoll()) return;
            timer = setTimeout(function () { checkNow(); }, nextDelay());
        }

        function checkNow() {
            clearTimeout(timer);
            timer = null;
            if (state.stopped || state.userPaused || state.inFlight) return Promise.resolve();
            // The one condition for ever sending a request.
            if (!isVisible() || !hasFocus()) return Promise.resolve();

            state.inFlight = true;
            state.requests++;

            return fetch(opts.versionUrl, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (res) {
                if (res.status === 401 || res.status === 403 || res.status === 419) {
                    state.authFailures++;
                    state.failures++;
                    if (state.authFailures >= 3) {
                        state.stopped = true;
                        if (opts.onStop) opts.onStop();
                    }
                    return null;
                }
                if (!res.ok) { state.failures++; return null; }
                // A session that ended is redirected to /login (HTML, not JSON).
                if (res.redirected && /\/login/.test(res.url)) {
                    state.authFailures++;
                    state.failures++;
                    if (state.authFailures >= 3) { state.stopped = true; if (opts.onStop) opts.onStop(); }
                    return null;
                }
                return res.json();
            }).then(function (json) {
                if (!json || typeof json.version !== 'string') return;
                state.failures = 0;
                state.authFailures = 0;
                if (opts.onTick) opts.onTick(json);
                if (state.version !== json.version) {
                    var previous = state.version;
                    state.version = json.version;
                    if (opts.onChange) opts.onChange(json, previous);
                }
            }).catch(function () {
                state.failures++;
            }).then(function () {
                state.inFlight = false;
                schedule();
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (isVisible()) {
                // Coming back always checks straight away -- a tab hidden for
                // more than LONG_HIDDEN_MS is the case that matters most, but
                // there is no reason to wait after a short absence either.
                state.hiddenSince = null;
                checkNow();
            } else {
                state.hiddenSince = Date.now();
                clearTimeout(timer);
                timer = null;
            }
        });
        window.addEventListener('focus', function () { checkNow(); });
        window.addEventListener('blur', function () { clearTimeout(timer); timer = null; });
        window.addEventListener('pageshow', function (event) {
            // Restored from the back/forward cache: the frozen page still
            // holds its old version, so this check finds the change.
            if (event.persisted) {
                state.failures = 0;
                state.inFlight = false;
                checkNow();
            }
        });

        return {
            state: state,
            checkNow: checkNow,
            start: function () { checkNow(); },
            pause: function () { state.userPaused = true; clearTimeout(timer); timer = null; },
            resume: function () { state.userPaused = false; checkNow(); },
            isPaused: function () { return state.userPaused; },
            stop: function () { state.stopped = true; clearTimeout(timer); timer = null; },
        };
    }

    window.ChloeLive = { createPoller: createPoller, LONG_HIDDEN_MS: LONG_HIDDEN_MS };

    // =====================================================================
    // THE WORKSTATION PAGES
    // =====================================================================
    var cfg = document.getElementById('wsl-config');
    if (!cfg) return;

    var KIND = cfg.getAttribute('data-kind');
    var STATS_URL = cfg.getAttribute('data-stats-url') || '';
    var statusEl = document.getElementById('wsl-status');
    var statusTimer = null;
    var regionDirty = false;
    var regionBusy = false;

    function region() {
        return document.querySelector('[data-live-region]');
    }

    // ---- count animation + highlight ----------------------------------
    function animateCount(node, from, to) {
        if (REDUCED_MOTION || from === to) {
            node.textContent = String(to);
            return;
        }
        var start = null;
        var DURATION = 300;
        function step(ts) {
            if (start === null) start = ts;
            var t = Math.min(1, (ts - start) / DURATION);
            node.textContent = String(Math.round(from + (to - from) * t));
            if (t < 1) requestAnimationFrame(step);
            else node.textContent = String(to);
        }
        requestAnimationFrame(step);
    }

    function flash(node) {
        node.classList.remove('wsl-flash');
        // Restart the CSS animation if it was already running.
        void node.offsetWidth;
        node.classList.add('wsl-flash');
        clearTimeout(node.__wslFlashTimer);
        node.__wslFlashTimer = setTimeout(function () { node.classList.remove('wsl-flash'); }, 1500);
    }

    function setNumber(node, value) {
        var current = parseInt((node.textContent || '').replace(/[^\d-]/g, ''), 10);
        if (isNaN(current)) current = 0;
        if (current === value) return;
        animateCount(node, current, value);
        flash(node);
    }

    function applyStats(data) {
        if (data.stats) {
            document.querySelectorAll('[data-live-stat]').forEach(function (node) {
                var key = node.getAttribute('data-live-stat');
                if (Object.prototype.hasOwnProperty.call(data.stats, key)) setNumber(node, data.stats[key]);
            });
        }
        (data.rooms || []).forEach(function (room) {
            document.querySelectorAll('[data-live-room="' + room.id + '"] [data-live-count]').forEach(function (node) {
                var key = node.getAttribute('data-live-count');
                if (Object.prototype.hasOwnProperty.call(room, key)) setNumber(node, room[key]);
            });
        });
    }

    function fetchStats() {
        if (!STATS_URL) return Promise.resolve();
        return fetch(STATS_URL, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) { if (data) applyStats(data); })
            .catch(function () { /* the next poll tries again */ });
    }

    // ---- server-rendered region ---------------------------------------
    function isTyping() {
        var a = document.activeElement;
        if (!a || a === document.body) return false;
        var tag = (a.tagName || '').toUpperCase();
        if (tag === 'TEXTAREA' || tag === 'SELECT' || a.isContentEditable) return true;
        if (tag === 'INPUT') {
            var type = (a.getAttribute('type') || 'text').toLowerCase();
            return ['button', 'submit', 'checkbox', 'radio', 'reset', 'image'].indexOf(type) === -1;
        }
        return false;
    }

    function modalOpen() {
        return !!document.querySelector('dialog[open]');
    }

    function showStatus(text) {
        if (!statusEl) return;
        statusEl.textContent = text;
        clearTimeout(statusTimer);
        statusTimer = setTimeout(function () { statusEl.textContent = ''; }, 3000);
    }

    /** Inline <script>s inside a swapped region do not run by themselves -- re-run them with THIS page's nonce. */
    function rerunScripts(container) {
        var nonceSource = document.querySelector('script[nonce]');
        var nonce = nonceSource ? (nonceSource.nonce || nonceSource.getAttribute('nonce')) : '';
        container.querySelectorAll('script').forEach(function (old) {
            if (old.src) return;
            var s = document.createElement('script');
            if (nonce) s.setAttribute('nonce', nonce);
            s.textContent = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
    }

    function refreshRegion() {
        var current = region();
        if (!current) return Promise.resolve();

        // Never under the user's hands: a modal is open, or they are typing.
        if (modalOpen() || isTyping() || regionBusy) {
            regionDirty = true;
            return Promise.resolve();
        }
        regionBusy = true;
        regionDirty = false;

        return fetch(window.location.href, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (res) {
            if (!res.ok || (res.redirected && /\/login/.test(res.url))) return null;
            return res.text();
        }).then(function (html) {
            if (!html) return;
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.querySelector('[data-live-region]');
            var target = region();
            if (!fresh || !target) return;
            if (modalOpen() || isTyping()) { regionDirty = true; return; }
            if ((fresh.textContent || '').replace(/\s+/g, ' ').trim() === (target.textContent || '').replace(/\s+/g, ' ').trim()) return;

            var activeId = document.activeElement && target.contains(document.activeElement) ? document.activeElement.id : '';
            var scrollX = window.scrollX, scrollY = window.scrollY;
            target.innerHTML = fresh.innerHTML;
            rerunScripts(target);
            if (activeId) {
                var again = document.getElementById(activeId);
                if (again) again.focus({ preventScroll: true });
            }
            window.scrollTo(scrollX, scrollY);
            showStatus('Updated just now');
        }).catch(function () { /* the next change tries again */ })
            .then(function () { regionBusy = false; });
    }

    // Applies a postponed refresh as soon as the user lets go.
    function flushDirty() {
        if (regionDirty && !modalOpen() && !isTyping()) refreshRegion();
    }
    document.addEventListener('focusout', function () { setTimeout(flushDirty, 50); });
    document.addEventListener('close', function () { setTimeout(flushDirty, 50); }, true);
    setInterval(flushDirty, 1000);

    // Seat Details: the "open modal" buttons live inside the swapped
    // region, so their load-time listeners are gone after a swap. One
    // delegated handler covers both cases (it never double-opens).
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-live-region] [data-open-modal]') : null;
        if (!button) return;
        var dialog = document.getElementById(button.getAttribute('data-open-modal'));
        if (dialog && !dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
    });

    // ---- wire it up ----------------------------------------------------
    var poller = createPoller({
        versionUrl: cfg.getAttribute('data-version-url'),
        interval: parseInt(cfg.getAttribute('data-interval'), 10) || 15000,
        version: cfg.getAttribute('data-version'),
        onChange: function () {
            if (KIND === 'cgs') fetchStats();
            refreshRegion();
        },
    });
    window.ChloeLive.poller = poller;
    poller.start();
})();
