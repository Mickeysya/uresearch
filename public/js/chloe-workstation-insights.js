/*
 * Round 6: the Workstation Insights page's drawing/interaction layer.
 * Reads the embedded fact rows (#ins-data) and meta (#ins-meta), and
 * uses window.ChloeInsightsAggregate (public/js/chloe-insights-aggregate.js,
 * pure, no DOM) for every count. Two layers, exactly like Power BI:
 *
 *   FILTERS (the five dropdowns)  -> change the POPULATION. Every chart
 *     rebuilds from the new population; axes/offsets are recomputed from
 *     it and may move.
 *   HIGHLIGHT (clicking a mark)   -> never removes anything. The same
 *     SVG elements stay in the DOM with the same geometry; only their
 *     fill-opacity and value labels change (ghost vs full colour).
 *
 * No <canvas>, no external library, nothing in this file ever sets a
 * style="" attribute on an element in markup -- every visual property is
 * set via element.style.xxx / setAttribute from this nonce-exempt
 * same-origin script, which the CSP rule already allows.
 */
(function () {
    'use strict';

    var AGG = window.ChloeInsightsAggregate;
    var dataEl = document.getElementById('ins-data');
    var metaEl = document.getElementById('ins-meta');
    if (!AGG || !dataEl || !metaEl) return;

    var ROWS = JSON.parse(dataEl.textContent || '[]');
    var META = JSON.parse(metaEl.textContent || '{}');
    var OCCUPIED_ROWS = ROWS.filter(function (r) { return r.occupied; });

    var PAGE = document.getElementById('wsi-page');
    var REDUCED_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var TRANSITION_MS = REDUCED_MOTION ? 0 : 250;

    /** The five dropdown dimensions -- these alone form the FILTER population; every other named dimension is highlight-only. */
    var FILTER_DIMS = ['block', 'room', 'gender', 'programme', 'key'];

    var COLORS = {
        blue: '#004B85',
        blue2: '#0066B3',
        gold: '#C88A12',
        orange: '#F68B33',
        red: '#C62828',
        green: '#2E8B57',
        slate: '#606C73',
        track: '#E4E4DA',
        grid: '#E9E9E1',
    };

    /** Round 7 PART 3.2: one-shade-darker hover token per base colour -- same hue, never a different colour family, used for both SVG marks (via drawMark/drawHBarSegment) and anywhere else a base colour needs a hover variant. */
    var COLOR_HOVER = {
        '#004B85': '#003A68',
        '#0066B3': '#00518E',
        '#C88A12': '#A6730D',
        '#F68B33': '#D66F1F',
        '#C62828': '#A31F1F',
        '#2E8B57': '#246E46',
        '#606C73': '#4C555C',
    };
    function hoverColorFor(color) {
        return COLOR_HOVER[color] || color;
    }

    var STATUS_COLOR = { occupied: COLORS.blue, available: COLORS.green, maintenance: COLORS.slate, awaiting_key_return: COLORS.gold };
    var STATUS_LABEL = { occupied: 'Occupied', available: 'Available', maintenance: 'Maintenance', awaiting_key_return: 'Awaiting Key Return' };
    var STATUS_ORDER = ['occupied', 'available', 'maintenance', 'awaiting_key_return'];

    var KEY_COLOR = { not_requested: COLORS.slate, pending_collection: COLORS.gold, collected: COLORS.green, return_pending: COLORS.orange };
    var KEY_LABEL = { not_requested: 'Not Requested', pending_collection: 'Pending Collection', collected: 'Collected', return_pending: 'Return Pending' };
    var KEY_ORDER = ['not_requested', 'pending_collection', 'collected', 'return_pending'];
    /** this page's own 'key' value -> the landing page's key_status query value. */
    var KEY_TO_LANDING = { not_requested: 'not_requested', pending_collection: 'requested', collected: 'collected', return_pending: 'return_pending' };

    var EXPIRY_COLOR = { '0_3': COLORS.red, '3_6': COLORS.orange, '6_12': COLORS.blue2, '12_plus': COLORS.blue };
    var EXPIRY_LABEL = { '0_3': '0-3 months', '3_6': '3-6 months', '6_12': '6-12 months', '12_plus': 'Over 12 months' };
    var EXPIRY_ORDER = ['0_3', '3_6', '6_12', '12_plus'];

    var STAY_LABEL = { lt6: 'Under 6 months', '6_12': '6-12 months', '12_18': '12-18 months', '18_plus': 'Over 18 months' };
    var STAY_ORDER = ['lt6', '6_12', '12_18', '18_plus'];

    var DUE_LABEL = { overdue: 'Overdue', due_7: 'Due within 7 days', due_8_14: 'Due in 8-14 days' };
    var DUE_COLOR = { overdue: COLORS.red, due_7: COLORS.orange, due_8_14: COLORS.gold };

    var ALMOST_FULL_PERCENT = META.almost_full_percent || 90;

    // ---- room/programme vocab, from meta -----------------------------
    var ROOM_BY_CODE = {};
    (META.rooms || []).forEach(function (r) { ROOM_BY_CODE[r.code] = r; });
    var BLOCKS = unique((META.rooms || []).map(function (r) { return r.block; })).sort();

    function unique(arr) {
        var seen = {}, out = [];
        arr.forEach(function (v) { if (v != null && !seen[v]) { seen[v] = true; out.push(v); } });
        return out;
    }

    // =====================================================================
    // STATE
    // =====================================================================
    var filters = AGG.emptyFilters();
    var highlight = AGG.emptyFilters();
    var grain = null; // resolved on first render from the forecast span
    var grainUserChosen = false;
    var drillPath = []; // stack of {grain, key, months[]} pushed by "Drill down"
    var population = ROWS; // recomputed on every filter change

    function recomputePopulation() {
        var f = {};
        FILTER_DIMS.forEach(function (d) { f[d] = filters[d]; });
        population = AGG.filteredRows(ROWS, f);
    }

    // =====================================================================
    // URL STATE
    // =====================================================================
    function readStateFromUrl() {
        var qs = window.location.search;
        var params = new URLSearchParams(qs);
        var parsedFilters = AGG.emptyFilters();
        var parsedHighlight = AGG.emptyFilters();

        FILTER_DIMS.forEach(function (d) {
            var raw = params.get('f_' + d);
            if (raw) parsedFilters[d] = raw.split(',').filter(Boolean);
        });
        Object.keys(parsedHighlight).forEach(function (d) {
            var raw = params.get('h_' + d);
            if (raw) parsedHighlight[d] = raw.split(',').filter(Boolean);
        });

        filters = parsedFilters;
        highlight = parsedHighlight;

        var g = params.get('grain');
        if (g === 'year' || g === 'quarter' || g === 'month') {
            grain = g;
            grainUserChosen = true;
        }
    }

    function writeStateToUrl() {
        var parts = [];
        FILTER_DIMS.forEach(function (d) {
            if (filters[d] && filters[d].length) parts.push('f_' + d + '=' + filters[d].map(encodeURIComponent).join(','));
        });
        Object.keys(highlight).forEach(function (d) {
            if (highlight[d] && highlight[d].length) parts.push('h_' + d + '=' + highlight[d].map(encodeURIComponent).join(','));
        });
        if (grainUserChosen) parts.push('grain=' + grain);

        var qs = parts.length ? '?' + parts.join('&') : '';
        window.history.replaceState(null, '', window.location.pathname + qs);

        // Round 8 PART 4.1: "The Refresh link reloads the page with the
        // same filters" -- keep its href in sync with the URL state we
        // just wrote, rather than the bare route the server rendered.
        var refreshLink = document.getElementById('wsi-refresh-link');
        if (refreshLink) refreshLink.href = window.location.pathname + qs;
    }

    // =====================================================================
    // SMALL HELPERS
    // =====================================================================
    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        attrs = attrs || {};
        for (var k in attrs) {
            if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
            if (k === 'class') e.className = attrs[k];
            else if (k === 'text') e.textContent = attrs[k];
            else e.setAttribute(k, attrs[k]);
        }
        if (text != null) e.textContent = text;
        return e;
    }

    function svgEl(tag, attrs) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        attrs = attrs || {};
        for (var k in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
        }
        return e;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function round1(n) {
        return Math.round(n * 10) / 10;
    }

    function percentOf(part, whole) {
        return whole > 0 ? round1((part / whole) * 100) : 0;
    }

    /** 3-5 "nice" integer ticks from zero, max >= 10% above the largest context value, min axis max 5. */
    function niceAxisMax(maxValue) {
        var target = Math.max(5, maxValue * 1.1);
        var pow = Math.pow(10, Math.floor(Math.log(target) / Math.LN10));
        var candidates = [1, 2, 2.5, 5, 10].map(function (m) { return m * pow; });
        var step = candidates.find(function (c) { return target / c <= 5; }) || candidates[candidates.length - 1];
        var max = Math.ceil(target / step) * step;
        var ticks = [];
        for (var v = 0; v <= max + 0.001; v += step) ticks.push(Math.round(v));
        return { max: max, step: step, ticks: ticks };
    }

    function splitLabelTwoLines(text) {
        // Any multi-word label wraps, not just long ones -- found live:
        // a column chart's category labels are centred over narrow
        // (36-56px) columns with modest gaps, so even an "unsplit"
        // 12-14 char single-line label like "12-18 months" was wide
        // enough to collide with its neighbour's own label. Two shorter
        // stacked lines never do.
        if (!text || text.indexOf(' ') === -1) return [text];
        var mid = Math.floor(text.length / 2);
        var best = -1, bestDist = Infinity;
        for (var i = 0; i < text.length; i++) {
            if (text[i] === ' ' && Math.abs(i - mid) < bestDist) {
                bestDist = Math.abs(i - mid);
                best = i;
            }
        }
        if (best === -1) return [text];
        return [text.slice(0, best), text.slice(best + 1)];
    }

    /**
     * Truncates an already-in-the-DOM SVG <text> element with an
     * ellipsis if its rendered width exceeds maxWidth -- used only as a
     * last resort for a single-line label whose expanded column budget
     * still isn't enough for an exceptionally long string (e.g. a
     * programme name with a long qualifier). getComputedTextLength()
     * measures the REAL rendered width for the page's own font, so this
     * works correctly regardless of font metrics.
     */
    function fitSvgTextWidth(textEl, maxWidth) {
        if (!textEl.getComputedTextLength || textEl.getComputedTextLength() <= maxWidth) return;
        var full = textEl.textContent;
        var lo = 0, hi = full.length;
        while (lo < hi) {
            var mid = Math.ceil((lo + hi) / 2);
            textEl.textContent = full.slice(0, mid) + '…';
            if (textEl.getComputedTextLength() <= maxWidth) lo = mid; else hi = mid - 1;
        }
        textEl.textContent = full.slice(0, lo) + '…';
    }

    var MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function monthLabel(ym) {
        var p = ym.split('-');
        return MONTH_NAMES[parseInt(p[1], 10) - 1] + ' ' + p[0];
    }
    function monthLabelShort(ym) {
        var p = ym.split('-');
        return MONTH_NAMES[parseInt(p[1], 10) - 1] + " '" + p[0].slice(2);
    }

    function currentMonthKey() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1);
    }

    // =====================================================================
    // TOOLTIP (one element on document.body, PART 6.1)
    // =====================================================================
    var tooltipEl = el('div', { class: 'wsi-tooltip', role: 'tooltip', id: 'wsi-tooltip', hidden: 'hidden' });
    document.body.appendChild(tooltipEl);

    function showTooltip(x, y, title, sub, extra) {
        clear(tooltipEl);
        // Round 7 PART 5.2: every child gets an EXPLICIT colour class --
        // found last round that a bare, unclassed child relying purely
        // on CSS inheritance is exactly the kind of thing that silently
        // renders invisible if anything upstream ever changes.
        tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-title' }, title));
        if (sub) tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-value' }, sub));
        if (extra) tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-value' }, extra));
        tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-sub' }, 'Click to highlight · Ctrl+click to select more'));
        tooltipEl.hidden = false;

        var left = x + 12, top = y + 12;
        tooltipEl.style.left = left + 'px';
        tooltipEl.style.top = top + 'px';
        var rect = tooltipEl.getBoundingClientRect();
        if (rect.right > window.innerWidth) tooltipEl.style.left = Math.max(4, x - rect.width - 12) + 'px';
        if (rect.bottom > window.innerHeight) tooltipEl.style.top = Math.max(4, y - rect.height - 12) + 'px';
    }

    function showTooltipNear(targetEl, title, sub, extra) {
        var r = targetEl.getBoundingClientRect();
        showTooltip(r.left + r.width / 2, r.top, title, sub, extra);
    }

    function hideTooltip() {
        tooltipEl.hidden = true;
    }

    window.addEventListener('scroll', hideTooltip, true);
    window.addEventListener('resize', hideTooltip);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideTooltip(); });

    // =====================================================================
    // CLICKABLE MARK WIRING (PART 3.3/3.5)
    // =====================================================================
    /**
     * Makes `target` a clickable, keyboard-operable mark for dimension
     * `dim`/value `value`: tabindex 0, role=button, aria-pressed, click
     * (with Ctrl/Cmd/Shift = additive) and Enter/Space toggle the
     * highlight via AGG.toggleFilter(); hover/focus show the tooltip
     * built by `tooltipFn()`.
     */
    function wireClickable(target, dim, value, tooltipFn, scopeRows) {
        target.classList.add('wsi-mark');
        // Belt-and-suspenders alongside the CSS default of
        // `pointer-events: none` on decorative <text> -- any element
        // actually wired as a mark must always be clickable, whatever
        // else later draws on top of it.
        target.style.pointerEvents = 'auto';
        target.setAttribute('tabindex', '0');
        target.setAttribute('role', 'button');
        target.setAttribute('aria-pressed', isSelected(dim, value) ? 'true' : 'false');
        // A highlight change never rebuilds this element (PART 3.4), so
        // nothing else would ever revisit this attribute -- register it
        // here and refreshAriaPressed() (called after every highlight
        // change) keeps it true/false for every mark, including this
        // one's own click.
        registerMark(target, function () { return isSelected(dim, value); });

        function activate(additive) {
            highlight = AGG.toggleFilter(highlight, dim, value, additive);
            onHighlightChange();
        }

        target.addEventListener('click', function (e) {
            activate(e.ctrlKey || e.metaKey || e.shiftKey);
        });
        target.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate(e.ctrlKey || e.metaKey || e.shiftKey);
            }
        });
        if (tooltipFn) {
            // Round 7 PART 5.4: "of which N highlighted" appended
            // automatically whenever a highlight is active -- computed
            // fresh on every hover/focus (never baked in at build time),
            // scoped to `scopeRows` when the mark's own meaning is
            // narrower than the whole population (e.g. one block's own
            // bar in the grouped chart).
            function withHighlightNote() {
                var t = tooltipFn();
                var extra = t[2] || null;
                if (anyHighlightActive()) {
                    var rows = scopeRows || population;
                    var hc = rows.filter(function (r) { return AGG.rowValueForDim(r, dim) === value && AGG.rowMatches(r, highlight); }).length;
                    extra = (extra ? extra + ' · ' : '') + 'of which ' + hc + ' highlighted';
                }
                return [t[0], t[1], extra];
            }
            target.addEventListener('mouseenter', function (e) { var t = withHighlightNote(); showTooltip(e.clientX, e.clientY, t[0], t[1], t[2]); });
            target.addEventListener('mousemove', function (e) { if (!tooltipEl.hidden) { tooltipEl.style.left = (e.clientX + 12) + 'px'; tooltipEl.style.top = (e.clientY + 12) + 'px'; } });
            target.addEventListener('mouseleave', hideTooltip);
            target.addEventListener('focus', function () { var t = withHighlightNote(); showTooltipNear(target, t[0], t[1], t[2]); });
            target.addEventListener('blur', hideTooltip);
        }
    }

    function isSelected(dim, value) {
        return (highlight[dim] || []).indexOf(value) !== -1;
    }

    /**
     * Every clickable mark currently in the DOM, with a function that
     * says whether IT is currently highlighted -- repopulated on every
     * full rebuild (renderAll()), read by refreshAriaPressed() after
     * every highlight change (a highlight never rebuilds a chart, so
     * nothing else would ever revisit a mark's aria-pressed).
     */
    var MARKS = [];
    function registerMark(el, isPressedFn) {
        MARKS.push({ el: el, isPressed: isPressedFn });
    }
    function refreshAriaPressed() {
        var active = anyHighlightActive();
        MARKS.forEach(function (m) {
            var pressed = m.isPressed();
            m.el.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            // Harmless on an SVG mark (selection there is shown by the
            // ghost/solid rect widths, not a class) -- these two classes
            // only have defined styles on .wsi-legend-item, where they
            // ARE the selection indicator (Round 7 PART 4.1).
            m.el.classList.toggle('wsi-selected', pressed);
            m.el.classList.toggle('wsi-ghosted', active && !pressed);
        });
    }

    // =====================================================================
    // SHARED LEGEND COMPONENT (Round 7 PART 4.1)
    // =====================================================================
    /**
     * entries: [{kind: 'swatch'|'line'|'dashed', color, label, count,
     * dim, value}]. An entry with both `dim` and `value` is a real
     * toggleable mark (same click/keyboard/highlight machinery as any
     * chart mark, via wireClickable); one without is a static key (a
     * colour meaning that isn't one of the page's own highlight
     * dimensions, e.g. the room-occupancy threshold colours).
     */
    function buildLegend(container, entries, caption) {
        var legend = el('div', { class: 'wsi-legend' });

        entries.forEach(function (entry) {
            var swatch;
            if (entry.kind === 'line') {
                swatch = el('span', { class: 'wsi-swatch-line' });
                swatch.style.background = entry.color;
            } else if (entry.kind === 'dashed') {
                swatch = el('span', { class: 'wsi-swatch-dashed' });
                swatch.style.color = entry.color;
            } else {
                swatch = el('span', { class: 'wsi-swatch' });
                swatch.style.background = entry.color;
            }

            var text = entry.label + (entry.count != null ? ' · ' + entry.count : '');
            var interactive = entry.dim != null && entry.value != null;
            var item = el(interactive ? 'button' : 'span', interactive ? { type: 'button', class: 'wsi-legend-item' } : { class: 'wsi-legend-item wsi-legend-static' });
            item.appendChild(swatch);
            item.appendChild(document.createTextNode(text));

            if (interactive) {
                wireClickable(item, entry.dim, entry.value, function () { return [entry.label, entry.count != null ? String(entry.count) : '']; });
            }

            legend.appendChild(item);
        });

        container.appendChild(legend);
        if (caption) container.appendChild(el('p', { class: 'wsi-legend-caption' }, caption));
        return legend;
    }

    // =====================================================================
    // LANDING-PAGE LINKS (PART 6.2) -- the REAL query param names
    // =====================================================================
    function statusToLandingSeatStatus(v) {
        return v === 'maintenance' ? 'disabled' : v;
    }

    function landingUrl(extra) {
        var params = new URLSearchParams();
        if (filters.block[0]) params.set('block', filters.block[0]);
        if (filters.room[0] && ROOM_BY_CODE[filters.room[0]]) params.set('location_id', String(ROOM_BY_CODE[filters.room[0]].id));
        if (filters.gender[0]) params.set('gender', filters.gender[0]);
        if (filters.key[0]) params.set('key_status', KEY_TO_LANDING[filters.key[0]] || filters.key[0]);

        extra = extra || {};
        if (extra.block) params.set('block', extra.block);
        if (extra.location_id) params.set('location_id', String(extra.location_id));
        if (extra.gender) params.set('gender', extra.gender);
        if (extra.seat_status) params.set('seat_status', extra.seat_status);
        if (extra.key_status) params.set('key_status', extra.key_status);

        var qs = params.toString();
        return META.operations_url + (qs ? '?' + qs : '');
    }

    // =====================================================================
    // FILTER DROPDOWNS (PART 4.2)
    // =====================================================================
    var DROPDOWN_LABEL_ALL = { block: 'All Blocks', room: 'All Rooms', gender: 'Any Gender', programme: 'Any Programme', key: 'Any Key Status' };

    function dropdownOptions(dim) {
        if (dim === 'block') return BLOCKS.map(function (b) { return { value: b, label: 'Block ' + b }; });
        if (dim === 'room') {
            return roomsInScope().map(function (r) { return { value: r.code, label: r.code }; });
        }
        if (dim === 'gender') return [{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }];
        if (dim === 'programme') return (META.programmes || []).map(function (p) { return { value: p, label: p }; });
        if (dim === 'key') return KEY_ORDER.map(function (k) { return { value: k, label: KEY_LABEL[k] }; });
        return [];
    }

    function roomsInScope() {
        return (META.rooms || []).filter(function (r) {
            if (filters.block.length && filters.block.indexOf(r.block) === -1) return false;
            if (filters.gender.length && filters.gender.indexOf(r.gender) === -1) return false;
            return true;
        });
    }

    function renderDropdowns() {
        FILTER_DIMS.forEach(function (dim) {
            var select = document.getElementById('wsi-filter-' + dim);
            if (!select) return;

            var options = dropdownOptions(dim);
            var validValues = options.map(function (o) { return o.value; });
            // Dropped out of scope (e.g. a room from a block no longer selected) -- drop it rather than show a stale selection.
            filters[dim] = filters[dim].filter(function (v) { return validValues.indexOf(v) !== -1; });

            clear(select);
            select.appendChild(el('option', { value: '' }, filters[dim].length > 1 ? 'Multiple' : DROPDOWN_LABEL_ALL[dim]));
            options.forEach(function (o) {
                var opt = el('option', { value: o.value }, o.label);
                if (filters[dim].length === 1 && filters[dim][0] === o.value) opt.selected = true;
                select.appendChild(opt);
            });
            if (filters[dim].length !== 1) select.value = '';
        });
    }

    function wireDropdowns() {
        FILTER_DIMS.forEach(function (dim) {
            var select = document.getElementById('wsi-filter-' + dim);
            if (!select) return;
            select.addEventListener('change', function () {
                filters[dim] = select.value ? [select.value] : [];
                onFilterChange();
            });
        });

        var resetBtn = document.getElementById('wsi-reset-filters');
        if (resetBtn) resetBtn.addEventListener('click', function () {
            filters = AGG.emptyFilters();
            onFilterChange();
        });

        var clearAllBtn = document.getElementById('wsi-clear-all');
        if (clearAllBtn) clearAllBtn.addEventListener('click', function () {
            highlight = AGG.emptyFilters();
            onHighlightChange();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                highlight = AGG.emptyFilters();
                onHighlightChange();
            }
        });

        var page = document.getElementById('wsi-page');
        if (page) page.addEventListener('click', function (e) {
            if (e.target === page) {
                highlight = AGG.emptyFilters();
                onHighlightChange();
            }
        });
    }

    // =====================================================================
    // CHIP STRIP + SUMMARY (PART 4.3)
    // =====================================================================
    var CHIP_LABEL_FOR = {
        block: function (v) { return 'Block ' + v; },
        room: function (v) { return v; },
        gender: function (v) { return v === 'male' ? 'Male' : 'Female'; },
        status: function (v) { return STATUS_LABEL[v] || v; },
        programme: function (v) { return v; },
        key: function (v) { return KEY_LABEL[v] || v; },
        expiry: function (v) { return EXPIRY_LABEL[v] || v; },
        stay: function (v) { return STAY_LABEL[v] || v; },
        release: function (v) { return v === 'later' ? 'Later' : monthLabel(v); },
        source: function (v) { return v === 'candidacy' ? 'Candidacy expiry' : 'Termination notice'; },
        due: function (v) { return DUE_LABEL[v] || v; },
        notice: function (v) { return v === 'true' ? 'Termination notice' : 'No termination notice'; },
    };
    var DIM_LABEL = {
        block: 'Block', room: 'Room', gender: 'Gender', status: 'Status', programme: 'Programme', key: 'Locker key',
        expiry: 'Candidacy expiry', stay: 'Length of stay', release: 'Release period', source: 'Release source',
        due: 'Key return', notice: 'Termination notice',
    };

    function makeChip(dim, selected, removeFn, ariaVerb) {
        var text = selected.map(function (v) { return (CHIP_LABEL_FOR[dim] || function (x) { return x; })(v); }).join(', ');
        var full = DIM_LABEL[dim] + ': ' + text;
        var shown = full.length > 40 ? full.slice(0, 40) + '…' : full;

        var chip = el('span', { class: 'wsi-chip', title: full });
        chip.appendChild(el('span', { class: 'wsi-chip-text' }, shown));
        var x = el('button', { type: 'button', class: 'wsi-chip-x', 'aria-label': 'Remove ' + DIM_LABEL[dim] + ' ' + ariaVerb }, '×');
        x.addEventListener('click', removeFn);
        chip.appendChild(x);
        return chip;
    }

    /**
     * Final polish PART 1.1: the strip (and its chips, Clear all and
     * summary) now mounts only once a dropdown FILTER or a click
     * HIGHLIGHT is active -- otherwise it renders nothing (not even an
     * empty box), and the plain .wsi-strip-tip caption under the filter
     * panel carries the hint instead. A 150ms slide-in plays when the
     * strip first mounts (skipped under prefers-reduced-motion via the
     * CSS transition rule itself, which that media query omits).
     */
    function renderChipsAndSummary() {
        var chipStrip = document.getElementById('wsi-chip-strip');
        var strip = document.getElementById('wsi-strip');
        var tip = document.getElementById('wsi-strip-tip');
        var clearAllBtn = document.getElementById('wsi-clear-all');
        clear(chipStrip);

        var anyFilter = FILTER_DIMS.some(function (d) { return filters[d] && filters[d].length; });
        var anyHighlight = Object.keys(highlight).some(function (d) { return highlight[d] && highlight[d].length; });
        var anyActive = anyFilter || anyHighlight;
        clearAllBtn.hidden = !anyHighlight;

        FILTER_DIMS.forEach(function (dim) {
            var selected = filters[dim];
            if (!selected || !selected.length) return;
            chipStrip.appendChild(makeChip(dim, selected, function () {
                filters[dim] = [];
                var select = document.getElementById('wsi-filter-' + dim);
                if (select) select.value = '';
                onFilterChange();
            }, 'filter'));
        });

        Object.keys(highlight).forEach(function (dim) {
            var selected = highlight[dim];
            if (!selected || !selected.length) return;
            chipStrip.appendChild(makeChip(dim, selected, function () {
                highlight[dim] = [];
                onHighlightChange();
            }, 'highlight'));
        });

        var wasHidden = strip.hidden;
        strip.hidden = !anyActive;
        tip.hidden = anyActive;

        if (anyActive && wasHidden) {
            strip.classList.add('wsi-strip-entering');
            // Force a style flush so the browser registers the "entering"
            // (pre-transition) state before the next frame removes it --
            // otherwise the add+remove would collapse into one frame and
            // no transition would ever play.
            void strip.offsetWidth;
            requestAnimationFrame(function () { strip.classList.remove('wsi-strip-entering'); });
        }

        var summary = AGG.summaryCounts(population, filters, highlight);
        document.getElementById('wsi-summary').textContent = 'Showing ' + summary.matching + ' of ' + summary.total + ' ' + summary.unit;
    }

    // =====================================================================
    // SELECTED SEATS PANEL (PART 6.2)
    // =====================================================================
    var selectedPage = 1;
    var SELECTED_PER_PAGE = 25;

    function selectedRows() {
        return AGG.filteredRows(population, highlight);
    }

    function renderSelectedPanel() {
        var panel = document.getElementById('wsi-selected-panel');
        var anyHighlight = Object.keys(highlight).some(function (d) { return highlight[d] && highlight[d].length; });
        var rows = selectedRows();

        panel.hidden = !anyHighlight;
        if (!anyHighlight) return;

        document.getElementById('wsi-selected-title').textContent = 'Selected seats (' + rows.length + ')';

        var landingLink = document.getElementById('wsi-selected-landing-link');
        landingLink.href = landingUrl({});

        var body = document.getElementById('wsi-selected-body');
        if (body.hidden) return; // only render the (possibly large) table while the panel is open

        var wrap = document.getElementById('wsi-selected-table-wrap');
        clear(wrap);

        if (rows.length === 0) {
            wrap.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match this selection.'));
            return;
        }

        var totalPages = Math.max(1, Math.ceil(rows.length / SELECTED_PER_PAGE));
        selectedPage = Math.min(selectedPage, totalPages);
        var pageRows = rows.slice((selectedPage - 1) * SELECTED_PER_PAGE, selectedPage * SELECTED_PER_PAGE);

        var table = el('table', { class: 'wsi-selected-table' });
        var thead = el('thead');
        var headRow = el('tr');
        ['Seat', 'Block', 'Room', 'Status', 'Locker key', 'Candidacy expiry', 'Release period', ''].forEach(function (h) { headRow.appendChild(el('th', {}, h)); });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = el('tbody');
        pageRows.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', {}, 'Seat ' + r.seat_no));
            tr.appendChild(el('td', {}, r.block));
            tr.appendChild(el('td', {}, r.room));
            tr.appendChild(el('td', {}, STATUS_LABEL[r.status] || r.status));
            tr.appendChild(el('td', {}, KEY_LABEL[r.key] || '—'));
            tr.appendChild(el('td', {}, EXPIRY_LABEL[r.expiry] || '—'));
            tr.appendChild(el('td', {}, r.release_month === 'none' ? '—' : monthLabel(r.release_month)));
            var linkTd = el('td');
            var a = el('a', { class: 'wsi-link', href: r.seat_url, target: '_blank', rel: 'noopener' }, 'Open seat →');
            a.style.display = 'inline';
            linkTd.appendChild(a);
            tr.appendChild(linkTd);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);

        var pager = el('div', { class: 'wsi-selected-pager' });
        var prev = el('button', { type: 'button', class: 'wsi-page-btn' }, 'Previous');
        var next = el('button', { type: 'button', class: 'wsi-page-btn' }, 'Next');
        prev.disabled = selectedPage <= 1;
        next.disabled = selectedPage >= totalPages;
        prev.addEventListener('click', function () { selectedPage--; renderSelectedPanel(); });
        next.addEventListener('click', function () { selectedPage++; renderSelectedPanel(); });
        pager.appendChild(prev);
        pager.appendChild(el('span', {}, 'Page ' + selectedPage + ' of ' + totalPages));
        pager.appendChild(next);
        wrap.appendChild(pager);
    }

    (function wireSelectedToggle() {
        var toggle = document.getElementById('wsi-selected-toggle');
        var body = document.getElementById('wsi-selected-body');
        toggle.addEventListener('click', function () {
            body.hidden = !body.hidden;
            toggle.setAttribute('aria-expanded', body.hidden ? 'false' : 'true');
            if (!body.hidden) { selectedPage = 1; renderSelectedPanel(); }
        });
    })();

    // =====================================================================
    // KPI ROW (PART 4.4)
    // =====================================================================
    function kpiDefs() {
        var nextMonthKey = (function () { var p = currentMonthKey().split('-'); var y = +p[0], m = +p[1]; m++; if (m > 12) { m = 1; y++; } return y + '-' + (m < 10 ? '0' : '') + m; })();

        return [
            {
                border: 'wsi-kpi-red', label: 'Key returns overdue', dim: 'due', value: 'overdue',
                view: landingUrl({ key_status: 'overdue' }),
            },
            {
                border: 'wsi-kpi-gold', label: 'Rooms at or above ' + ALMOST_FULL_PERCENT + '% occupancy', dim: 'room', value: null,
                values: almostFullRoomCodes(), view: META.operations_url,
            },
            {
                border: 'wsi-kpi-orange', label: 'Seats in a termination notice', dim: 'notice', value: 'true',
                view: landingUrl({ seat_status: 'termination_notice' }),
            },
            {
                border: 'wsi-kpi-red', label: 'Candidacy expires within 3 months', dim: 'expiry', value: '0_3',
                view: META.operations_url,
            },
            {
                border: 'wsi-kpi-blue', label: 'Seats that may be released next month', dim: 'release', value: nextMonthKey,
                view: META.operations_url,
            },
        ];
    }

    function almostFullRoomCodes() {
        return (META.rooms || []).filter(function (r) {
            var occCount = OCCUPIED_ROWS.filter(function (row) { return row.room === r.code; }).length;
            return percentOf(occCount, r.capacity) >= ALMOST_FULL_PERCENT;
        }).map(function (r) { return r.code; });
    }

    function kpiContextCount(def) {
        if (def.values) return def.values.length;
        return (population.filter(function (r) { return AGG.rowValueForDim(r, def.dim) === def.value; })).length;
    }

    function kpiHighlightedCount(def) {
        if (def.values) {
            return def.values.filter(function (code) { return isSelected('room', code); }).length;
        }
        var counts = AGG.highlightCounts(population, def.dim, highlight);
        return counts[def.value] || 0;
    }

    function anyHighlightActive() {
        return Object.keys(highlight).some(function (d) { return highlight[d] && highlight[d].length; });
    }

    function renderKpiRow() {
        var row = document.getElementById('wsi-kpi-row');
        clear(row);

        var defs = kpiDefs();
        var hasAny = defs.some(function (d) { return kpiContextCount(d) > 0; });

        if (!hasAny) {
            row.appendChild(el('p', { class: 'wsi-kpi-empty' }, 'Nothing needs attention.'));
            return;
        }

        var active = anyHighlightActive();

        defs.forEach(function (def) {
            var context = kpiContextCount(def);
            var highlighted = active ? kpiHighlightedCount(def) : context;
            var shown = active ? highlighted : context;

            var card = el('button', { type: 'button', class: 'wsi-kpi-card ' + def.border });
            var valueLine = el('div', { class: 'wsi-kpi-value wsi-tabular' }, String(shown));
            if (active && highlighted !== context) {
                valueLine.appendChild(el('span', { class: 'wsi-kpi-value-ghost' }, 'of ' + context));
            }
            card.appendChild(valueLine);
            card.appendChild(el('div', { class: 'wsi-kpi-label' }, def.label));

            var pressed = def.values
                ? (def.values.length > 0 && def.values.every(function (c) { return isSelected('room', c); }))
                : isSelected(def.dim, def.value);
            card.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            // Rebuilt fresh on every highlight change (unlike a chart
            // mark), so the class is simplest set directly here rather
            // than through the MARKS registry.
            if (pressed) card.classList.add('wsi-selected');
            card.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('a')) return;
                if (def.values) {
                    var allSelected = def.values.length > 0 && def.values.every(function (c) { return highlight.room.indexOf(c) !== -1; });
                    highlight.room = allSelected ? [] : def.values.slice();
                } else {
                    highlight = AGG.toggleFilter(highlight, def.dim, def.value, false);
                }
                onHighlightChange();
            });

            var viewWrap = el('div', {});
            var viewLink = el('a', { class: 'wsi-link wsi-kpi-view', href: def.view }, 'View →');
            viewLink.style.display = 'inline';
            viewLink.addEventListener('click', function (e) { e.stopPropagation(); });
            viewWrap.appendChild(viewLink);
            card.appendChild(viewWrap);

            row.appendChild(card);
        });
    }

    // =====================================================================
    // SHARED: a single "ghost + solid" segment inside a horizontal track
    // =====================================================================
    /**
     * Draws one segment of a (possibly stacked) horizontal bar: a ghost
     * rect spanning the segment's full CONTEXT width (fixed; never moves
     * on a highlight-only change) and a solid rect on top spanning the
     * HIGHLIGHTED width within it, both starting at the same offset.
     * Returns {ghost, solid} so a later highlight-only repaint can just
     * update widths/opacity, never rebuild.
     */
    /**
     * Round 7: ghost + solid now live inside a wrapping <g class="wsi-mark">
     * -- hover/focus-visible on the GROUP drives both children via plain
     * CSS descendant selectors (see the stylesheet), so no JS repaint is
     * needed on hover, and no outline/ring is ever drawn (PART 3.1).
     * The group (not the solid rect, whose width can shrink to 0 under
     * an unrelated highlight) is the right click/focus target.
     */
    function drawHBarSegment(svg, x, y, width, height, color, offsetPx, contextPx, highlightedPx) {
        var group = svgEl('g', {});
        var ghost = svgEl('rect', { x: x + offsetPx, y: y, width: Math.max(0, contextPx), height: height, fill: color, 'fill-opacity': '0.2', class: 'wsi-ghost-rect' });
        var solid = svgEl('rect', { x: x + offsetPx, y: y, width: Math.max(0, highlightedPx), height: height, fill: color, 'fill-opacity': '1', class: 'wsi-solid-rect' });
        solid.style.setProperty('--wsi-mark-hover-fill', hoverColorFor(color));
        [ghost, solid].forEach(function (r) {
            r.style.transition = 'width ' + TRANSITION_MS + 'ms ease, opacity ' + TRANSITION_MS + 'ms ease, fill 120ms ease';
        });
        // The ghost rect (constant geometry, never shrinks to 0) is the
        // real hit area; the group is hovered/focused/clicked via it
        // bubbling, and tabindex lives on the group itself so keyboard
        // focus works independently of any child's pointer-events.
        solid.style.pointerEvents = 'none';
        group.appendChild(ghost);
        group.appendChild(solid);
        svg.appendChild(group);
        return { group: group, ghost: ghost, solid: solid };
    }

    // =====================================================================
    // Round 8 PART 1: generic grouped bar chart, shared by "How many
    // seats are in each state?" (Round 7), "Locker key status of seat
    // holders" and "Male rooms versus female rooms" -- one group per
    // groups[i], bars.length bars inside each group, ONE shared
    // zero-based axis across every group. Geometry is byte-for-byte the
    // same as the Round 7 status chart on purpose (group header row,
    // 36px/20px/8px bar rows, value column on the right, legend, hidden
    // table) so all three charts read as one design.
    //
    // groups: [{ key, rows, headerText, headerMeta, headerDim, headerValue, headerUnit }]
    // bars: [{ key, label, color }]
    // cfg: {
    //   countFor(group, bar) -> context count,
    //   totalFor(group) -> the group's own denominator for the % column,
    //   barPressed(group, bar) -> bool,
    //   highlightedCountFor(group, bar) -> count,
    //   onBarClick(group, bar, additive),
    //   tooltipFor(group, bar, count) -> [title, sub],
    // }
    // =====================================================================
    function buildGroupedBarChart(containerId, ariaLabel, groups, bars, cfg) {
        var container = document.getElementById(containerId);
        clear(container);
        var cache = { groups: {} };

        var width = container.clientWidth || 600;
        var narrow = width < 700;
        var rowH = narrow ? 52 : 36, barThickness = 20;
        var headerH = 20, headerGap = 16, groupGap = 32;
        var labelW = narrow ? 0 : 130, valueColW = 110;
        var trackW = Math.max(60, width - labelW - valueColW - 8);
        var leftGutter = labelW;

        var maxCount = Math.max.apply(null, [5].concat(groups.map(function (g) {
            return Math.max.apply(null, bars.map(function (b) { return cfg.countFor(g, b); }));
        })));
        var scale = niceAxisMax(maxCount);

        var groupH = headerH + headerGap + bars.length * rowH;
        var totalH = groups.length * groupH + Math.max(0, groups.length - 1) * groupGap + 28;

        var svg = svgEl('svg', { width: width, height: totalH, role: 'img', 'aria-label': ariaLabel });
        var hiddenTable = el('table', { class: 'wsi-visually-hidden' });
        var hiddenBody = el('tbody');
        hiddenTable.appendChild(hiddenBody);

        var plotBottom = totalH - 28;
        // Final polish PART 1.4 (found live): on a narrow card
        // (leftGutter 0, no row-label column), the "0" tick sits at
        // x=0 -- text-anchor:middle then centres the glyph ON that
        // edge, and an SVG's own default overflow:hidden genuinely
        // clips its left half in the real render. Anchoring the "0"
        // tick at 'start' instead keeps it entirely inside the plot;
        // every other tick is nowhere near the edge, so it keeps
        // 'middle' as before.
        scale.ticks.forEach(function (t) {
            var x = leftGutter + (t / scale.max) * trackW;
            svg.appendChild(svgEl('line', { x1: x, x2: x, y1: 0, y2: plotBottom, stroke: COLORS.grid }));
            var lab = svgEl('text', { x: x, y: plotBottom + 16, 'text-anchor': t === 0 ? 'start' : 'middle', class: 'wsi-axis-label' });
            lab.textContent = String(t);
            svg.appendChild(lab);
        });

        var y = 0;
        groups.forEach(function (g) {
            var headerLabel = svgEl('text', { x: 0, y: y + 14, class: 'wsi-category-label', 'font-weight': '700', fill: 'var(--wsi-text-dark)', 'font-size': '13' });
            headerLabel.textContent = g.headerText;
            svg.appendChild(headerLabel);
            wireClickable(headerLabel, g.headerDim, g.headerValue, function () { return [g.headerText, g.rows.length + (g.headerUnit || ' seats')]; });

            var headerMeta = svgEl('text', { x: width, y: y + 14, 'text-anchor': 'end', class: 'wsi-axis-label' });
            headerMeta.textContent = g.headerMeta;
            svg.appendChild(headerMeta);

            y += headerH + headerGap;

            var barInfos = {};
            var total = cfg.totalFor(g);
            bars.forEach(function (b) {
                var contextCount = cfg.countFor(g, b);
                var contextPx = scale.max > 0 ? (contextCount / scale.max) * trackW : 0;

                var labelY = narrow ? y + 14 : y + rowH / 2 + 4;
                var barY = narrow ? y + 26 : y + (rowH - barThickness) / 2;
                var label = svgEl('text', { x: 0, y: labelY, class: 'wsi-category-label' });
                label.textContent = b.label;
                svg.appendChild(label);

                var seg = drawHBarSegment(svg, leftGutter, barY, trackW, barThickness, b.color, 0, contextPx, contextPx);
                var markGroup = seg.group;
                markGroup.classList.add('wsi-mark');
                markGroup.style.pointerEvents = 'auto';
                markGroup.setAttribute('tabindex', '0');
                markGroup.setAttribute('role', 'button');
                markGroup.setAttribute('aria-pressed', cfg.barPressed(g, b) ? 'true' : 'false');
                registerMark(markGroup, function () { return cfg.barPressed(g, b); });

                function tooltipExtra() {
                    if (!anyHighlightActive()) return null;
                    return 'of which ' + cfg.highlightedCountFor(g, b) + ' highlighted';
                }
                markGroup.addEventListener('mouseenter', function (e) { var t = cfg.tooltipFor(g, b, contextCount); showTooltip(e.clientX, e.clientY, t[0], t[1], tooltipExtra()); });
                markGroup.addEventListener('mousemove', function (e) { if (!tooltipEl.hidden) { tooltipEl.style.left = (e.clientX + 12) + 'px'; tooltipEl.style.top = (e.clientY + 12) + 'px'; } });
                markGroup.addEventListener('mouseleave', hideTooltip);
                markGroup.addEventListener('focus', function () { var t = cfg.tooltipFor(g, b, contextCount); showTooltipNear(markGroup, t[0], t[1]); });
                markGroup.addEventListener('blur', hideTooltip);
                markGroup.addEventListener('click', function (e) { cfg.onBarClick(g, b, e.ctrlKey || e.metaKey || e.shiftKey); });
                markGroup.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cfg.onBarClick(g, b, e.ctrlKey || e.metaKey || e.shiftKey); } });

                var valueLabel = svgEl('text', { x: width, y: barY + barThickness / 2 + 4, 'text-anchor': 'end', class: 'wsi-value-label' });
                var baseText = contextCount + ' · ' + percentOf(contextCount, total) + '%';
                valueLabel.textContent = baseText;
                svg.appendChild(valueLabel);

                barInfos[b.key] = { seg: seg, contextCount: contextCount, contextPx: contextPx, total: total, valueLabel: valueLabel, baseText: baseText };
                y += rowH;
            });

            cache.groups[g.key] = barInfos;

            var hrow = el('tr');
            hrow.appendChild(el('td', {}, g.headerText));
            bars.forEach(function (b) { hrow.appendChild(el('td', {}, b.label + ': ' + barInfos[b.key].contextCount)); });
            hiddenBody.appendChild(hrow);

            y += groupGap;
        });

        container.appendChild(svg);
        container.appendChild(hiddenTable);

        return cache;
    }

    function paintGroupedBarChart(cache, groups, bars, cfg) {
        if (!cache) return;
        var active = anyHighlightActive();
        groups.forEach(function (g) {
            var barInfos = cache.groups[g.key];
            if (!barInfos) return;
            bars.forEach(function (b) {
                var info = barInfos[b.key];
                if (!info) return;
                var highlightedCount = cfg.highlightedCountFor(g, b);
                var ratio = info.contextCount > 0 ? highlightedCount / info.contextCount : 0;
                info.seg.solid.setAttribute('width', Math.max(0, info.contextPx * ratio));

                clear(info.valueLabel);
                if (active && highlightedCount !== info.contextCount) {
                    var bold = svgEl('tspan', { 'font-weight': '700' });
                    bold.textContent = highlightedCount + ' · ' + percentOf(highlightedCount, info.total) + '%';
                    info.valueLabel.appendChild(bold);
                    var ghostPart = svgEl('tspan', { fill: 'var(--wsi-text-grey)', 'font-weight': '400' });
                    ghostPart.textContent = '  of ' + info.contextCount;
                    info.valueLabel.appendChild(ghostPart);
                } else {
                    info.valueLabel.textContent = info.baseText;
                }
            });
        });
    }

    // =====================================================================
    // 4.4b HOW MANY SEATS ARE IN EACH STATE? (grouped bar chart, Round 7 PART 1)
    // =====================================================================
    /** Spec's own required order for this chart; deliberately different from the generic STATUS_ORDER the gender chart still uses. */
    var STATUS_ORDER_GROUPED = ['occupied', 'available', 'awaiting_key_return', 'maintenance'];
    var statusByBlockCache = null;

    function buildStatusByBlock() {
        var container = document.getElementById('wsi-status-by-block');
        clear(container);
        statusByBlockCache = { bars: {}, valueEls: {} };

        var width = container.clientWidth || 600;
        var narrow = width < 700;
        var rowH = narrow ? 52 : 36, barThickness = 20;
        var headerH = 20, headerGap = 16, groupGap = 32;
        var labelW = narrow ? 0 : 130, valueColW = 110;
        var trackW = Math.max(60, width - labelW - valueColW - 8);
        var leftGutter = labelW;

        var blockPop = {};
        BLOCKS.forEach(function (b) { blockPop[b] = population.filter(function (r) { return r.block === b; }); });

        var maxCount = Math.max.apply(null, [5].concat(BLOCKS.map(function (b) {
            return Math.max.apply(null, STATUS_ORDER_GROUPED.map(function (s) { return blockPop[b].filter(function (r) { return r.status === s; }).length; }));
        })));
        var scale = niceAxisMax(maxCount);

        var groupH = headerH + headerGap + STATUS_ORDER_GROUPED.length * rowH;
        var totalH = BLOCKS.length * groupH + Math.max(0, BLOCKS.length - 1) * groupGap + 28; // +28 for the axis tick labels under the last group

        var svg = svgEl('svg', { width: width, height: totalH, role: 'img', 'aria-label': 'Seat status by block, grouped by status' });
        var hiddenTable = el('table', { class: 'wsi-visually-hidden' });
        var hiddenBody = el('tbody');
        hiddenTable.appendChild(hiddenBody);

        var plotBottom = totalH - 28;
        // light vertical gridlines, shared axis, drawn full chart height once
        // Final polish PART 1.4 (found live): same fix as
        // buildGroupedBarChart's own axis -- on a narrow card
        // (leftGutter 0) the "0" tick sits at x=0, and text-anchor:
        // middle centres its glyph ON that edge, which an SVG's
        // default overflow:hidden then genuinely clips. 'start' keeps
        // it fully inside the plot; this is the one chart Round 8 did
        // not migrate onto the shared buildGroupedBarChart helper, so
        // it carried its own (identical) copy of the bug.
        scale.ticks.forEach(function (t) {
            var x = leftGutter + (t / scale.max) * trackW;
            svg.appendChild(svgEl('line', { x1: x, x2: x, y1: 0, y2: plotBottom, stroke: COLORS.grid }));
            var lab = svgEl('text', { x: x, y: plotBottom + 16, 'text-anchor': t === 0 ? 'start' : 'middle', class: 'wsi-axis-label' });
            lab.textContent = String(t);
            svg.appendChild(lab);
        });

        var y = 0;
        BLOCKS.forEach(function (block) {
            var rows = blockPop[block];
            var total = rows.length;
            var occCount = rows.filter(function (r) { return r.status === 'occupied'; }).length;

            var headerLabel = svgEl('text', { x: 0, y: y + 14, class: 'wsi-category-label wsi-group-header-label', 'font-weight': '700', fill: 'var(--wsi-text-dark)', 'font-size': '13' });
            headerLabel.textContent = 'Block ' + block;
            svg.appendChild(headerLabel);
            wireClickable(headerLabel, 'block', block, function () { return ['Block ' + block, total + ' seats']; });

            var headerMeta = svgEl('text', { x: width, y: y + 14, 'text-anchor': 'end', class: 'wsi-axis-label' });
            headerMeta.textContent = total + ' seats · ' + percentOf(occCount, total) + '% occupied';
            svg.appendChild(headerMeta);

            y += headerH + headerGap;

            var segs = {};
            STATUS_ORDER_GROUPED.forEach(function (status) {
                var contextCount = rows.filter(function (r) { return r.status === status; }).length;
                var contextPx = scale.max > 0 ? (contextCount / scale.max) * trackW : 0;

                var labelY = narrow ? y + 14 : y + rowH / 2 + 4;
                var barY = narrow ? y + 26 : y + (rowH - barThickness) / 2;
                var label = svgEl('text', { x: 0, y: labelY, class: 'wsi-category-label' });
                label.textContent = STATUS_LABEL[status];
                svg.appendChild(label);

                var seg = drawHBarSegment(svg, leftGutter, barY, trackW, barThickness, STATUS_COLOR[status], 0, contextPx, contextPx);
                wireClickable(seg.group, 'status', status, function () {
                    return [STATUS_LABEL[status], contextCount + ' of ' + total + ' in Block ' + block];
                }, rows);

                var valueLabel = svgEl('text', { x: width, y: barY + barThickness / 2 + 4, 'text-anchor': 'end', class: 'wsi-value-label' });
                valueLabel.textContent = contextCount + ' · ' + percentOf(contextCount, total) + '%';
                svg.appendChild(valueLabel);

                segs[status] = { seg: seg, contextCount: contextCount, contextPx: contextPx, total: total, valueLabel: valueLabel, baseText: contextCount + ' · ' + percentOf(contextCount, total) + '%' };
                y += rowH;
            });

            statusByBlockCache.bars[block] = segs;

            var hrow = el('tr');
            hrow.appendChild(el('td', {}, 'Block ' + block));
            STATUS_ORDER_GROUPED.forEach(function (s) { hrow.appendChild(el('td', {}, STATUS_LABEL[s] + ': ' + segs[s].contextCount)); });
            hiddenBody.appendChild(hrow);

            y += groupGap;
        });

        container.appendChild(svg);

        var totals = {};
        STATUS_ORDER_GROUPED.forEach(function (s) { totals[s] = population.filter(function (r) { return r.status === s; }).length; });
        buildLegend(container, STATUS_ORDER_GROUPED.map(function (s) {
            return { dim: 'status', value: s, color: STATUS_COLOR[s], label: STATUS_LABEL[s], count: totals[s] };
        }));

        container.appendChild(hiddenTable);

        paintStatusByBlock();
    }

    function paintStatusByBlock() {
        if (!statusByBlockCache) return;
        var active = anyHighlightActive();
        BLOCKS.forEach(function (block) {
            var segs = statusByBlockCache.bars[block];
            if (!segs) return;
            STATUS_ORDER_GROUPED.forEach(function (status) {
                var info = segs[status];
                var highlightedCount = population.filter(function (r) { return r.block === block && r.status === status && AGG.rowMatches(r, highlight); }).length;
                var ratio = info.contextCount > 0 ? highlightedCount / info.contextCount : 0;
                info.seg.solid.setAttribute('width', Math.max(0, info.contextPx * ratio));

                clear(info.valueLabel);
                if (active && highlightedCount !== info.contextCount) {
                    var bold = svgEl('tspan', { 'font-weight': '700' });
                    bold.textContent = highlightedCount + ' · ' + percentOf(highlightedCount, info.total) + '%';
                    info.valueLabel.appendChild(bold);
                    var ghostPart = svgEl('tspan', { fill: 'var(--wsi-text-grey)', 'font-weight': '400' });
                    ghostPart.textContent = '  of ' + info.contextCount;
                    info.valueLabel.appendChild(ghostPart);
                } else {
                    info.valueLabel.textContent = info.baseText;
                }
            });
        });
    }

    // =====================================================================
    // 4.5 SEAT RELEASE FORECAST (PART 3.7/5.1)
    // =====================================================================
    var forecastCache = null;

    function forecastFilters() {
        var f = {};
        FILTER_DIMS.forEach(function (d) { f[d] = filters[d]; });
        return f;
    }
    function roomScopeFilters() {
        return { block: filters.block, room: filters.room, gender: filters.gender };
    }

    function currentForecastData() {
        var monthly = AGG.forecastMonths(ROWS, forecastFilters(), roomScopeFilters(), currentMonthKey(), 36);
        var g = grainUserChosen ? grain : AGG.defaultGrain(monthly.months.length);
        grain = g;
        var periods = AGG.groupPeriods(monthly.months, grain);
        if (monthly.later) {
            periods = periods.concat([{
                key: 'later', label: 'Later',
                from_candidacy: monthly.later.from_candidacy, from_termination: monthly.later.from_termination,
                releases: monthly.later.releases, projected_available: monthly.later.projected_available,
                months: laterMonthKeys(monthly),
            }]);
        }
        return { monthly: monthly, periods: periods };
    }

    /** Re-slices the drilled period's own months into the next finer grain, scoped to just that period. */
    function drilledPeriods() {
        if (drillPath.length === 0) return currentForecastData();
        var top = drillPath[drillPath.length - 1];
        var monthly = AGG.forecastMonths(ROWS, forecastFilters(), roomScopeFilters(), currentMonthKey(), 36);
        var subMonths = monthly.months.filter(function (m) { return top.months.indexOf(m.month) !== -1; });
        var childGrain = top.grain === 'year' ? 'quarter' : 'month';
        return { monthly: monthly, periods: AGG.groupPeriods(subMonths, childGrain), grainOverride: childGrain };
    }

    /**
     * groupPeriods()'s own 'label' is a plain machine key ("2026-11",
     * "2026-Q4", "2026", or "later") -- shared with the PHP reference
     * and the parity test, so it stays that simple on purpose. This is
     * the display-only formatting, grain-aware: a month key becomes
     * "Nov '26", a quarter key "Q4 2026", a year key is shown as-is.
     * Found live: reusing the month-only heuristic for every grain
     * printed literal "undefined" for quarter/year labels (it tried to
     * read a month number out of "2026-Q4").
     */
    function periodDisplayLabel(p, effectiveGrain) {
        if (p.key === 'later') return 'Later';
        if (effectiveGrain === 'month') return monthLabelShort(p.label);
        if (effectiveGrain === 'quarter') {
            var parts = p.label.split('-Q');
            return 'Q' + parts[1] + ' ' + parts[0];
        }
        return p.label; // 'year'
    }

    function laterMonthKeys(monthly) {
        var shown = monthly.months.map(function (m) { return m.month; });
        return unique(ROWS.filter(function (r) { return r.release_month !== 'none' && shown.indexOf(r.release_month) === -1; }).map(function (r) { return r.release_month; }));
    }

    /**
     * Round 8 PART 3.1: never create a horizontal scroll under this
     * card. If the DEFAULT (not user-chosen) grain would need more
     * columns than fit at the 36px floor, escalate to a coarser grain
     * (month -> quarter -> year) BEFORE building anything -- tried here,
     * against the real container width, not guessed from a fixed count.
     */
    function escalateGrainToFitWidth(plotW) {
        var minColW = 36, minGap = 8;
        var order = ['month', 'quarter', 'year'];
        var startAt = grainUserChosen ? order.indexOf(grain) : order.indexOf(grain || 'month');
        if (startAt === -1) startAt = 0;

        for (var i = startAt; i < order.length; i++) {
            var candidateGrain = order[i];
            var monthly = AGG.forecastMonths(ROWS, forecastFilters(), roomScopeFilters(), currentMonthKey(), 36);
            var candidatePeriods = AGG.groupPeriods(monthly.months, candidateGrain);
            if (monthly.later) candidatePeriods = candidatePeriods.concat([{ key: 'later' }]);
            var count = candidatePeriods.length;
            var needed = count * minColW + Math.max(0, count - 1) * minGap;

            if (needed <= plotW || grainUserChosen) {
                if (!grainUserChosen) grain = candidateGrain;
                return;
            }
        }
        // Still too many even at Year, or the user pinned a grain that
        // does not fit -- the per-column floor below (16px) absorbs the
        // rest; still never a horizontal scroll past the container.
    }

    function buildForecast() {
        var container = document.getElementById('wsi-forecast');
        var width = container.clientWidth || 600;
        var leftGutter = 44, rightPad = 8;
        var plotW = width - leftGutter - rightPad;

        if (!drillPath.length) escalateGrainToFitWidth(plotW);

        var data = drillPath.length ? drilledPeriods() : currentForecastData();
        var periods = data.periods;
        var effectiveGrain = data.grainOverride || grain;

        updateGrainControl(effectiveGrain);
        updateBreadcrumb();
        updateForecastSubtitle(effectiveGrain);

        clear(container);
        forecastCache = { points: {}, cols: {} };

        if (periods.length === 0) {
            container.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match these filters.'));
            return;
        }

        var narrow = width < 480;

        if (narrow) {
            buildForecastTable(container, periods);
            return;
        }

        // Round 8 PART 3.1: column width 36-64px, GAPS grow to fill the
        // full width -- the chart never needs more width than its
        // container has, so no horizontal scrollbar is ever possible.
        var minColW = 36, maxColW = 64, minGap = 8;
        var colW = Math.max(minColW, Math.min(maxColW, (plotW - (periods.length - 1) * minGap) / periods.length));
        if (colW * periods.length + (periods.length - 1) * minGap > plotW) {
            // Even the 36px floor doesn't fit (very many periods) -- shrink further as a last resort; still no scroll.
            colW = Math.max(12, (plotW - (periods.length - 1) * minGap) / periods.length);
        }
        var colGap = periods.length > 1 ? Math.max(minGap, (plotW - periods.length * colW) / (periods.length - 1)) : 0;
        var totalW = width;

        function xForIndex(i) { return leftGutter + i * (colW + colGap); }

        var topH = 240, lineH = 160, labelHeadroom = 24;

        var maxReleaseVal = Math.max.apply(null, [5].concat(periods.map(function (p) { return p.releases; })));
        var topScale = niceAxisMax(maxReleaseVal);
        var maxProjected = Math.max.apply(null, [5].concat(periods.map(function (p) { return p.projected_available; })));
        var lineScale = niceAxisMax(maxProjected);

        var svgTop = svgEl('svg', { width: totalW, height: topH + 24, role: 'img', 'aria-label': 'Seats that may be released, by period' });

        topScale.ticks.forEach(function (t) {
            var y = topH - labelHeadroom - (t / topScale.max) * (topH - labelHeadroom - 20);
            svgTop.appendChild(svgEl('line', { x1: leftGutter, x2: totalW, y1: y, y2: y, stroke: COLORS.grid }));
            var lab = svgEl('text', { x: leftGutter - 8, y: y + 4, 'text-anchor': 'end', class: 'wsi-axis-label' });
            lab.textContent = String(t);
            svgTop.appendChild(lab);
        });

        // Round 8 PART 3.5: label every point when there are at most 12,
        // otherwise every second one (the lit points always get a label
        // too, regardless of this pattern -- see paintForecast()).
        var showEveryLabel = periods.length <= 12;

        periods.forEach(function (p, i) {
            var x = xForIndex(i);
            var baseline = topH - labelHeadroom;
            var usableH = baseline - 20;
            var candH = topScale.max > 0 ? (p.from_candidacy / topScale.max) * usableH : 0;
            var termH = topScale.max > 0 ? (p.from_termination / topScale.max) * usableH : 0;

            var colGroup = svgEl('g', {});
            var termRect = svgEl('rect', { x: x, y: baseline - termH, width: colW, height: termH, fill: COLORS.orange, 'fill-opacity': '0.2', class: 'wsi-ghost-rect' });
            var termSolid = svgEl('rect', { x: x, y: baseline - termH, width: colW, height: termH, fill: COLORS.orange, class: 'wsi-solid-rect' });
            var candRect = svgEl('rect', { x: x, y: baseline - termH - candH, width: colW, height: candH, fill: COLORS.blue, 'fill-opacity': '0.2', class: 'wsi-ghost-rect' });
            var candSolid = svgEl('rect', { x: x, y: baseline - termH - candH, width: colW, height: candH, fill: COLORS.blue, class: 'wsi-solid-rect' });
            termSolid.style.setProperty('--wsi-mark-hover-fill', hoverColorFor(COLORS.orange));
            candSolid.style.setProperty('--wsi-mark-hover-fill', hoverColorFor(COLORS.blue));
            [termRect, termSolid, candRect, candSolid].forEach(function (r) { r.style.transition = 'opacity ' + TRANSITION_MS + 'ms ease, fill 120ms ease'; });
            termSolid.style.pointerEvents = 'none';
            candSolid.style.pointerEvents = 'none';
            colGroup.appendChild(termRect);
            colGroup.appendChild(candRect);
            colGroup.appendChild(termSolid);
            colGroup.appendChild(candSolid);
            svgTop.appendChild(colGroup);

            var valueLabel = svgEl('text', { x: x + colW / 2, y: baseline - termH - candH - 6, 'text-anchor': 'middle', class: 'wsi-value-label' });
            valueLabel.textContent = String(p.releases);
            if (showEveryLabel || i % 2 === 0) svgTop.appendChild(valueLabel);

            var lines = splitLabelTwoLines(periodDisplayLabel(p, effectiveGrain));
            lines.forEach(function (line, li) {
                var t = svgEl('text', { x: x + colW / 2, y: topH + 4 + li * 13, 'text-anchor': 'middle', class: 'wsi-category-label' });
                t.textContent = line;
                svgTop.appendChild(t);
            });

            var hit = svgEl('rect', { x: x, y: 0, width: colW, height: topH, fill: 'transparent' });
            colGroup.appendChild(hit);
            colGroup.classList.add('wsi-mark');
            colGroup.style.pointerEvents = 'auto';
            colGroup.setAttribute('tabindex', '0');
            colGroup.setAttribute('role', 'button');
            colGroup.setAttribute('aria-pressed', (highlight.release || []).some(function (m) { return p.months.indexOf(m) !== -1; }) ? 'true' : 'false');
            registerMark(colGroup, function () { return (highlight.release || []).some(function (m) { return p.months.indexOf(m) !== -1; }); });
            function forecastTooltipExtra() {
                if (!anyHighlightActive()) return null;
                var hc = population.filter(function (r) { return p.months.indexOf(r.release_month) !== -1 && AGG.rowMatches(r, highlight); }).length;
                return 'of which ' + hc + ' highlighted';
            }
            colGroup.addEventListener('mouseenter', function (e) { showTooltip(e.clientX, e.clientY, periodDisplayLabel(p, effectiveGrain), p.releases + ' seats may be released', forecastTooltipExtra()); });
            colGroup.addEventListener('mousemove', function (e) { if (!tooltipEl.hidden) { tooltipEl.style.left = (e.clientX + 12) + 'px'; tooltipEl.style.top = (e.clientY + 12) + 'px'; } });
            colGroup.addEventListener('mouseleave', hideTooltip);
            colGroup.addEventListener('focus', function () { showTooltipNear(colGroup, p.label, p.releases + ' seats may be released'); });
            colGroup.addEventListener('blur', hideTooltip);
            colGroup.addEventListener('click', function (e) { applyPeriodHighlight(p.months, e.ctrlKey || e.metaKey || e.shiftKey); });
            colGroup.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    applyPeriodHighlight(p.months, e.ctrlKey || e.metaKey || e.shiftKey);
                }
            });

            forecastCache.cols[p.key] = { candRect: candRect, candSolid: candSolid, termRect: termRect, termSolid: termSolid, candH: candH, termH: termH, releases: p.releases, label: valueLabel, months: p.months };
        });

        container.appendChild(svgTop);

        var svgLine = svgEl('svg', { width: totalW, height: lineH, role: 'img', 'aria-label': 'Projected available seats at period end' });
        lineScale.ticks.forEach(function (t) {
            var y = lineH - 24 - (t / lineScale.max) * (lineH - 24 - 16);
            svgLine.appendChild(svgEl('line', { x1: leftGutter, x2: totalW, y1: y, y2: y, stroke: COLORS.grid }));
            var lab = svgEl('text', { x: leftGutter - 8, y: y + 4, 'text-anchor': 'end', class: 'wsi-axis-label' });
            lab.textContent = String(t);
            svgLine.appendChild(lab);
        });

        var nowCount = data.monthly.months.length ? (data.monthly.months[0].projected_available - data.monthly.months[0].releases) : 0;
        var nowY = lineH - 24 - (nowCount / lineScale.max) * (lineH - 24 - 16);
        svgLine.appendChild(svgEl('line', { x1: leftGutter, x2: totalW, y1: nowY, y2: nowY, stroke: COLORS.green, 'stroke-dasharray': '4 4' }));
        // Final polish PART 1.3: the on-plot "Now" chip used to live
        // here, but it still collided with the tick labels at some
        // widths/counts. Removed -- the live count now lives only in
        // the legend entry below ("Now: N available seats today"),
        // which can never collide with anything on the plot.

        var points = periods.map(function (p, i) {
            var x = xForIndex(i) + colW / 2;
            var y = lineH - 24 - (p.projected_available / lineScale.max) * (lineH - 24 - 16);
            return { x: x, y: y, p: p };
        });

        var pathD = points.map(function (pt, i) { return (i === 0 ? 'M' : 'L') + pt.x + ',' + pt.y; }).join(' ');
        var path = svgEl('path', { d: pathD, fill: 'none', stroke: COLORS.green, 'stroke-width': '2' });
        svgLine.appendChild(path);

        points.forEach(function (pt, i) {
            var dot = svgEl('circle', { cx: pt.x, cy: pt.y, r: 4, fill: COLORS.green });
            var label = svgEl('text', { x: pt.x, y: Math.max(12, pt.y - 10), 'text-anchor': 'middle', class: 'wsi-value-label' });
            label.textContent = String(pt.p.projected_available);
            svgLine.appendChild(dot);
            svgLine.appendChild(label);
            var defaultVisible = showEveryLabel || i % 2 === 0;
            if (!defaultVisible) label.style.opacity = '0';
            // A lit point shows its label even when the every-second-point rule would skip it -- but only
            // if neighbouring labels cannot collide: labels are ~28px wide, so below ~30px between points
            // only the every-second labels may show (the dot still grows and the tooltip still has the value).
            forecastCache.points[pt.p.key] = { dot: dot, label: label, value: pt.p.projected_available, defaultVisible: defaultVisible, labelsFit: colW >= 30 };
        });
        forecastCache.path = path;

        container.appendChild(svgLine);

        var explainer = el('p', { class: 'wsi-footer-note' },
            'Dashed line = seats available today. The line above it shows the maximum seats that could be available if every seat holder whose candidacy expires leaves.');
        container.appendChild(explainer);

        buildLegend(container, [
            { dim: 'source', value: 'candidacy', color: COLORS.blue, label: 'Candidacy expiry' },
            { dim: 'source', value: 'termination', color: COLORS.orange, label: 'Termination notice' },
            { kind: 'line', color: COLORS.green, label: 'Projected available seats' },
            { kind: 'dashed', color: COLORS.green, label: 'Now: ' + nowCount + ' available seats today' },
        ], 'Faded = not highlighted.');

        paintForecast(data);
    }

    function applyPeriodHighlight(monthKeys, additive) {
        if (!monthKeys || !monthKeys.length) return;
        var current = highlight.release || [];
        var isSameSet = current.length === monthKeys.length && monthKeys.every(function (m) { return current.indexOf(m) !== -1; });

        if (additive) {
            var next = current.slice();
            monthKeys.forEach(function (m) {
                var idx = next.indexOf(m);
                if (idx === -1) next.push(m); else next.splice(idx, 1);
            });
            highlight.release = next;
        } else {
            highlight.release = isSameSet ? [] : monthKeys.slice();
        }
        onHighlightChange();
    }

    function buildForecastTable(container, periods) {
        // Round 8 PART 3.1 follow-up: table-layout:fixed (see the
        // .wsi-forecast-table CSS rule) keeps this table within its
        // container at any width, including 390px, which a plain
        // auto-layout table could not guarantee for long single words
        // like "Termination".
        var table = el('table', { class: 'wsi-selected-table wsi-forecast-table' });
        var thead = el('thead');
        var head = el('tr');
        ['Period', 'Candidacy', 'Termination', 'Total releases', 'Projected available'].forEach(function (h) { head.appendChild(el('th', {}, h)); });
        thead.appendChild(head);
        table.appendChild(thead);
        var tbody = el('tbody');
        periods.forEach(function (p) {
            var tr = el('tr');
            [p.label, p.from_candidacy, p.from_termination, p.releases, p.projected_available].forEach(function (v) { tr.appendChild(el('td', {}, String(v))); });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    }

    function paintForecast(data) {
        if (!forecastCache) return;
        data.periods.forEach(function (p) {
            var col = forecastCache.cols[p.key];
            if (!col) return;
            var highlightedRows = population.filter(function (r) { return p.months.indexOf(r.release_month) !== -1 && AGG.rowMatches(r, highlight); });
            var hCand = highlightedRows.filter(function (r) { return r.release_source === 'candidacy'; }).length;
            var hTerm = highlightedRows.length - hCand;
            var candRatio = p.from_candidacy > 0 ? hCand / p.from_candidacy : 0;
            var termRatio = p.from_termination > 0 ? hTerm / p.from_termination : 0;

            var candTop = Number(col.candRect.getAttribute('y'));
            col.candSolid.setAttribute('height', col.candH * candRatio);
            col.candSolid.setAttribute('y', candTop + col.candH * (1 - candRatio));

            var termTop = Number(col.termRect.getAttribute('y'));
            col.termSolid.setAttribute('height', col.termH * termRatio);
            col.termSolid.setAttribute('y', termTop + col.termH * (1 - termRatio));

            col.label.style.opacity = (!anyHighlightActive() || highlightedRows.length > 0) ? '1' : '0.35';

            var pointInfo = forecastCache.points[p.key];
            if (pointInfo) {
                var lit = anyHighlightActive() && highlightedRows.length > 0;
                pointInfo.dot.setAttribute('r', lit ? 6 : 4);
                pointInfo.dot.setAttribute('fill-opacity', (!anyHighlightActive() || lit) ? '1' : '0.35');
                // Round 8 PART 3.5: a lit point ALWAYS shows its label,
                // even if it would normally be skipped by the every-
                // second-point rule; otherwise fall back to that rule.
                pointInfo.label.style.opacity = lit ? ((pointInfo.defaultVisible || pointInfo.labelsFit) ? '1' : '0') : (anyHighlightActive() ? '0' : (pointInfo.defaultVisible ? '1' : '0'));
            }
        });
    }

    function updateGrainControl(activeGrain) {
        var ctl = document.getElementById('wsi-grain-control');
        if (!ctl) return;
        ctl.querySelectorAll('button').forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-grain') === activeGrain ? 'true' : 'false');
        });
    }

    /** Round 8 PART 3.4: the subtitle always names the grain actually being shown, including when it was auto-escalated to fit the width. */
    function updateForecastSubtitle(activeGrain) {
        var el2 = document.getElementById('wsi-forecast-subtitle');
        if (!el2) return;
        var word = activeGrain === 'year' ? 'Year' : (activeGrain === 'quarter' ? 'Quarter' : 'Month');
        el2.textContent = 'Candidacy expiry and termination notices, ' + word.toLowerCase() + ' by ' + word.toLowerCase() + '.';
    }

    function updateBreadcrumb() {
        var bc = document.getElementById('wsi-forecast-breadcrumb');
        clear(bc);
        if (drillPath.length === 0) { bc.hidden = true; return; }
        bc.hidden = false;

        var all = el('button', {}, 'All ' + (drillPath[0].grain === 'year' ? 'years' : 'quarters'));
        all.addEventListener('click', function () { drillPath = []; rebuildForecastOnly(); });
        bc.appendChild(all);

        drillPath.forEach(function (step, i) {
            bc.appendChild(document.createTextNode(' › '));
            if (i === drillPath.length - 1) {
                bc.appendChild(document.createTextNode(step.label));
            } else {
                var btn = el('button', {}, step.label);
                btn.addEventListener('click', function () { drillPath = drillPath.slice(0, i + 1); rebuildForecastOnly(); });
                bc.appendChild(btn);
            }
        });
    }

    function rebuildForecastOnly() {
        buildForecast();
        maybeShowDrillButton();
    }

    function maybeShowDrillButton() {
        var card = document.getElementById('wsi-card-forecast');
        if (!card) return;
        var existing = document.getElementById('wsi-drill-btn');
        if (existing) existing.remove();

        var selectedMonths = highlight.release || [];
        if (selectedMonths.length === 0) return;

        var data = drillPath.length ? drilledPeriods() : currentForecastData();
        var matchingPeriods = data.periods.filter(function (p) { return p.months.some(function (m) { return selectedMonths.indexOf(m) !== -1; }); });
        var exactlyOne = matchingPeriods.length === 1 && matchingPeriods[0].months.length > 1;
        if (!exactlyOne) return;

        var period = matchingPeriods[0];
        var effectiveGrain = data.grainOverride || grain;
        if (effectiveGrain === 'month') return;

        var btn = el('button', { type: 'button', class: 'wsi-drill-btn', id: 'wsi-drill-btn' }, 'Drill down →');
        btn.addEventListener('click', function () {
            drillPath.push({ grain: effectiveGrain, key: period.key, label: periodDisplayLabel(period, effectiveGrain), months: period.months });
            highlight.release = [];
            rebuildForecastOnly();
            // Drilling in clears the release highlight (PART 3.7) --
            // found live: EVERY other chart kept showing its pre-drill
            // ghost/solid paint (and the chip strip/summary kept
            // showing the pre-drill selection) because only the
            // forecast chart itself was rebuilt above. repaintHighlightOnly()
            // re-applies the (now empty) highlight to every other
            // chart's already-built elements, same as any other
            // highlight change.
            repaintHighlightOnly();
            refreshAriaPressed();
            renderChipsAndSummary();
            renderKpiRow();
            renderSelectedPanel();
            writeStateToUrl();
        });
        var body = card.querySelector('.wsi-card-body');
        if (body) body.insertAdjacentElement('afterend', btn);
    }

    (function wireGrainControl() {
        var ctl = document.getElementById('wsi-grain-control');
        if (!ctl) return;
        ctl.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                grain = btn.getAttribute('data-grain');
                grainUserChosen = true;
                drillPath = [];
                rebuildForecastOnly();
                writeStateToUrl();
            });
        });
    })();

    // =====================================================================
    // 4.6a WHICH ROOMS ARE FILLING UP? (horizontal bars, grouped by block)
    // =====================================================================
    var roomsCache = null;

    function buildRooms() {
        var container = document.getElementById('wsi-rooms');
        clear(container);
        roomsCache = { rows: {} };

        var roomsPop = roomsInScope();
        if (roomsPop.length === 0) {
            container.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match these filters.'));
            return;
        }

        var width = container.clientWidth || 400;
        var narrow = width < 1100;
        var rowH = narrow ? 52 : 36;
        var labelW = Math.min(220, Math.max(60, width * 0.25));
        var valueColW = 90;
        var trackW = Math.max(60, width - labelW - valueColW - 30);

        var perRoom = roomsPop.map(function (r) {
            var occCount = OCCUPIED_ROWS.filter(function (row) { return row.room === r.code; }).length;
            return { room: r, occCount: occCount, pct: percentOf(occCount, r.capacity) };
        });

        var grouped = {};
        BLOCKS.forEach(function (b) { grouped[b] = []; });
        perRoom.forEach(function (p) { if (grouped[p.room.block]) grouped[p.room.block].push(p); });
        Object.keys(grouped).forEach(function (b) { grouped[b].sort(function (a, c) { return c.pct - a.pct; }); });

        var activeBlocks = BLOCKS.filter(function (b) { return grouped[b].length > 0; });

        // Round 8 PART 2.1: a block heading must never be the last
        // visible thing without its rooms -- made a REAL sticky HTML
        // element (position: sticky only works in the normal CSS box
        // flow, not on an SVG <text>), so each block gets its own small
        // SVG (just that block's own rows) preceded by a sticky heading,
        // instead of one giant SVG with the heading drawn as text inside it.
        var innerScroll = el('div', { class: 'wsi-rooms-scroll-inner' });

        activeBlocks.forEach(function (block) {
            var head = el('div', { class: 'wsi-rooms-sticky-header' }, 'Block ' + block);
            innerScroll.appendChild(head);

            var blockRows = grouped[block];
            var svg = svgEl('svg', { width: width, height: blockRows.length * rowH, role: 'img', 'aria-label': 'Block ' + block + ' room occupancy' });
            var y = 0;

            blockRows.forEach(function (p) {
                var rowY = y;
                var pillText = p.room.gender === 'male' ? 'M' : 'F';
                var pill = svgEl('circle', { cx: 10, cy: rowY + rowH / 2, r: 9, fill: '#ECEEF1' });
                svg.appendChild(pill);
                var pillLabel = svgEl('text', { x: 10, y: rowY + rowH / 2 + 3, 'text-anchor': 'middle', 'font-size': '9', 'font-weight': '700', fill: COLORS.slate });
                pillLabel.textContent = pillText;
                svg.appendChild(pillLabel);

                var codeLink = svgEl('a', {});
                codeLink.setAttributeNS('http://www.w3.org/1999/xlink', 'href', p.room.url);
                var codeText = svgEl('text', { x: 26, y: rowY + rowH / 2 + 4, class: 'wsi-category-label wsi-room-link-text', cursor: 'pointer', 'text-decoration': 'underline' });
                codeText.textContent = p.room.code;
                codeLink.appendChild(codeText);
                svg.appendChild(codeLink);

                var barX = labelW;
                var barY = rowY + (rowH - 18) / 2;
                var track = svgEl('rect', { x: barX, y: barY, width: trackW, height: 18, rx: 4, fill: COLORS.track });
                svg.appendChild(track);

                var color = p.pct >= ALMOST_FULL_PERCENT ? COLORS.red : (p.pct >= 75 ? COLORS.gold : COLORS.blue);
                var contextPx = (p.occCount / p.room.capacity) * trackW;
                var seg = drawHBarSegment(svg, barX, barY, trackW, 18, color, 0, contextPx, contextPx);

                var markerX = barX + trackW * (ALMOST_FULL_PERCENT / 100);
                svg.appendChild(svgEl('line', { x1: markerX, x2: markerX, y1: barY - 2, y2: barY + 20, stroke: COLORS.red, 'stroke-dasharray': '3 3' }));

                var valueLabel = svgEl('text', { x: barX + trackW + 10, y: rowY + rowH / 2 + 4, class: 'wsi-value-label' });
                valueLabel.textContent = p.occCount + '/' + p.room.capacity + ' · ' + p.pct + '%';
                svg.appendChild(valueLabel);

                // The full-row hit target lives INSIDE seg.group (not as
                // a separate sibling) so that hovering anywhere in the
                // row -- not just over the bar itself -- triggers the
                // group's :hover/:focus-visible CSS and darkens the bar.
                var hit = svgEl('rect', { x: 0, y: rowY, width: width, height: rowH, fill: 'transparent' });
                seg.group.appendChild(hit);
                wireClickable(seg.group, 'room', p.room.code, function () { return [p.room.code, p.occCount + '/' + p.room.capacity + ' occupied (' + p.pct + '%)']; });

                roomsCache.rows[p.room.code] = { seg: seg, occCount: p.occCount, capacity: p.room.capacity, valueLabel: valueLabel };
                y += rowH;
            });

            innerScroll.appendChild(svg);
        });

        // Round 8 PART 2.1: the scroll area is now obviously scrollable
        // -- a permanently visible styled scrollbar (CSS), a soft fade
        // at the bottom edge (hidden once scrolled to the end), and a
        // caption underneath. scrollWrap (position:relative) holds both
        // the actual scrolling element and the fade overlay, which is
        // positioned relative to IT, not the page.
        var scrollWrap = el('div', { class: 'wsi-rooms-scroll' });
        scrollWrap.appendChild(innerScroll);
        var fade = el('div', { class: 'wsi-scroll-fade' });
        scrollWrap.appendChild(fade);
        container.appendChild(scrollWrap);

        function updateFade() {
            var atEnd = innerScroll.scrollTop + innerScroll.clientHeight >= innerScroll.scrollHeight - 2;
            var scrollable = innerScroll.scrollHeight > innerScroll.clientHeight + 2;
            fade.style.display = (scrollable && !atEnd) ? 'block' : 'none';
        }
        innerScroll.addEventListener('scroll', updateFade);
        updateFade();

        container.appendChild(el('p', { class: 'wsi-scroll-caption' }, 'Scroll for more · ' + perRoom.length + ' room' + (perRoom.length === 1 ? '' : 's') + '.'));

        buildLegend(container, [
            { color: COLORS.blue, label: 'Under 75%' },
            { color: COLORS.gold, label: '75% to under ' + ALMOST_FULL_PERCENT + '%' },
            { color: COLORS.red, label: ALMOST_FULL_PERCENT + '% and above' },
            { kind: 'dashed', color: COLORS.red, label: 'Dashed line = ' + ALMOST_FULL_PERCENT + '% (almost full)' },
        ]);
        paintRooms();
    }

    function paintRooms() {
        if (!roomsCache) return;
        Object.keys(roomsCache.rows).forEach(function (code) {
            var info = roomsCache.rows[code];
            var highlightedCount = OCCUPIED_ROWS.filter(function (r) { return r.room === code && AGG.rowMatches(r, highlight); }).length;
            var ratio = info.occCount > 0 ? highlightedCount / info.occCount : 0;
            var contextPx = Number(info.seg.ghost.getAttribute('width'));
            info.seg.solid.setAttribute('width', contextPx * ratio);
            info.valueLabel.style.opacity = (!anyHighlightActive() || highlightedCount > 0) ? '1' : '0.4';
        });
    }

    // =====================================================================
    // Round 8 PART 1.2: MALE VS FEMALE ROOMS -- now a grouped bar chart,
    // same geometry as "How many seats are in each state?" (Round 7
    // PART 1 / Round 8 PART 1.1). Groups = Male rooms / Female rooms;
    // bars = Occupied / Available / Awaiting Key Return / Maintenance.
    // A bar click highlights gender AND status TOGETHER (two independent
    // toggleFilter() calls, same additive flag -- clicking the SAME bar
    // again clears both, since each call's own "already the sole value"
    // rule fires for both dims at once); a group-header click highlights
    // the gender alone.
    // =====================================================================
    var genderCache = null;
    var GENDER_BARS = STATUS_ORDER_GROUPED.map(function (s) { return { key: s, label: STATUS_LABEL[s], color: STATUS_COLOR[s] }; });

    function genderGroups() {
        return ['male', 'female'].map(function (gender) {
            var rows = population.filter(function (r) { return r.gender === gender; });
            var occCount = rows.filter(function (r) { return r.occupied; }).length;
            return {
                key: gender, gender: gender, rows: rows,
                headerText: gender === 'male' ? 'Male rooms' : 'Female rooms',
                headerMeta: percentOf(occCount, rows.length) + '% occupied',
                headerDim: 'gender', headerValue: gender, headerUnit: ' seats',
            };
        });
    }

    function buildGender() {
        var groups = genderGroups();
        genderCache = buildGroupedBarChart('wsi-gender', 'Occupancy of male and female rooms, by status', groups, GENDER_BARS, {
            countFor: function (g, b) { return g.rows.filter(function (r) { return r.status === b.key; }).length; },
            totalFor: function (g) { return g.rows.length; },
            barPressed: function (g, b) { return isSelected('gender', g.gender) && isSelected('status', b.key); },
            highlightedCountFor: function (g, b) { return g.rows.filter(function (r) { return r.status === b.key && AGG.rowMatches(r, highlight); }).length; },
            onBarClick: function (g, b, additive) {
                highlight = AGG.toggleFilter(highlight, 'gender', g.gender, additive);
                highlight = AGG.toggleFilter(highlight, 'status', b.key, additive);
                onHighlightChange();
            },
            tooltipFor: function (g, b, count) { return [b.label, count + ' of ' + g.rows.length + ' in ' + g.headerText]; },
        });

        var container = document.getElementById('wsi-gender');
        var combined = {};
        STATUS_ORDER_GROUPED.forEach(function (s) { combined[s] = population.filter(function (r) { return r.status === s; }).length; });
        buildLegend(container, STATUS_ORDER_GROUPED.map(function (s) {
            return { dim: 'status', value: s, color: STATUS_COLOR[s], label: STATUS_LABEL[s], count: combined[s] };
        }));

        paintGender();
    }

    function paintGender() {
        paintGroupedBarChart(genderCache, genderGroups(), GENDER_BARS, {
            highlightedCountFor: function (g, b) { return g.rows.filter(function (r) { return r.status === b.key && AGG.rowMatches(r, highlight); }).length; },
        });
    }

    // =====================================================================
    // 4.7a PROGRAMME BREAKDOWN
    // =====================================================================
    var programmesCache = null;
    var OTHERS_THRESHOLD = 8;

    function programmeGroups() {
        var context = AGG.context(population, 'programme');
        var sorted = Object.keys(context).sort(function (a, b) { return context[b] - context[a]; });
        var top = sorted.slice(0, OTHERS_THRESHOLD);
        var rest = sorted.slice(OTHERS_THRESHOLD);
        var groups = top.map(function (name) { return { name: name, names: [name], count: context[name] }; });
        if (rest.length) {
            groups.push({ name: 'Others', names: rest, count: rest.reduce(function (s, n) { return s + context[n]; }, 0) });
        }
        return groups;
    }

    function buildProgrammes() {
        var container = document.getElementById('wsi-programmes');
        clear(container);
        programmesCache = { rows: {} };

        var groups = programmeGroups();
        var holders = population.filter(function (r) { return r.occupied; }).length;

        if (groups.length === 0) {
            container.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match these filters.'));
            return;
        }

        var width = container.clientWidth || 700;
        // Narrow cards (phones): the programme names are long (up to ~70 characters), so each row is stacked -- the name on its own line
        // across the full width (truncated, full name in the tooltip), the bar and its value below it. No horizontal scroll.
        var stacked = width < 560;
        var rowH = stacked ? 54 : 36;
        // labelW has a 150px FLOOR, not just a 35%-of-width ceiling --
        // found live at 390px: a long programme name (e.g. "PhD Computer
        // & Information Sciences (Full-Time)") collided with its own
        // value text because 35% of a ~230px container left only ~80px
        // for the label. The floor can need more room than the
        // container has; the card scrolls horizontally when it does,
        // same technique as the column charts.
        var labelW = stacked ? 0 : Math.max(150, Math.min(360, width * 0.35));
        var valueColW = 110;
        var minTrackW = 60;
        var trackW = Math.max(minTrackW, width - labelW - valueColW - 16);
        var totalH = groups.length * rowH;
        var requiredWidth = labelW + minTrackW + valueColW + 16;
        if (stacked) requiredWidth = 0;
        var svgWidth = Math.max(width, requiredWidth);
        var scrolls = svgWidth > width;
        if (scrolls) container.classList.add('wsi-hscroll');

        var svg = svgEl('svg', { width: svgWidth, height: totalH, role: 'img', 'aria-label': 'Programme breakdown of seat holders' });
        // Attached to the live document BEFORE being populated --
        // fitSvgTextWidth() below calls getComputedTextLength(), which
        // needs a connected (laid-out) element to measure correctly; on
        // a detached node it silently returns 0, which is exactly the
        // bug found live (truncation never triggered, no matter how
        // long the label).
        container.appendChild(svg);
        var maxCount = Math.max.apply(null, [5].concat(groups.map(function (g) { return g.count; })));

        groups.forEach(function (g, i) {
            var y = i * rowH;
            var label = svgEl('text', { x: 0, y: stacked ? y + 15 : y + rowH / 2 + 4, class: 'wsi-category-label' });
            label.textContent = g.name;
            svg.appendChild(label);
            fitSvgTextWidth(label, stacked ? width - 4 : labelW - 8);
            if (label.textContent !== g.name) label.setAttribute('data-full-name', g.name); // the tooltip/aria still read the real, untruncated name via g.name in closures below

            var barY = stacked ? y + 26 : y + (rowH - 18) / 2;
            var contextPx = (g.count / maxCount) * trackW;
            var seg = drawHBarSegment(svg, labelW, barY, trackW, 18, COLORS.blue, 0, contextPx, contextPx);

            var valueLabel = svgEl('text', { x: labelW + trackW + 10, y: stacked ? barY + 13 : y + rowH / 2 + 4, class: 'wsi-value-label' });
            valueLabel.textContent = g.count + ' · ' + percentOf(g.count, holders) + '%';
            svg.appendChild(valueLabel);

            // Full-row hit target lives INSIDE seg.group (see the Rooms
            // chart's own comment on this) so hover/focus on any part of
            // the row darkens the bar via the group's CSS states.
            var hit = svgEl('rect', { x: 0, y: y, width: svgWidth, height: rowH, fill: 'transparent' });
            seg.group.appendChild(hit);
            var group = seg.group;
            group.classList.add('wsi-mark');
            group.style.pointerEvents = 'auto';
            group.setAttribute('tabindex', '0');
            group.setAttribute('role', 'button');
            function programmeIsPressed() { return g.names.length > 0 && g.names.every(function (n) { return isSelected('programme', n); }); }
            group.setAttribute('aria-pressed', programmeIsPressed() ? 'true' : 'false');
            registerMark(group, programmeIsPressed);
            function programmeTooltipExtra() {
                if (!anyHighlightActive()) return null;
                var hc = population.filter(function (r) { return r.programme != null && g.names.indexOf(r.programme) !== -1 && AGG.rowMatches(r, highlight); }).length;
                return 'of which ' + hc + ' highlighted';
            }
            group.addEventListener('mouseenter', function (e) { showTooltip(e.clientX, e.clientY, g.name, g.count + ' of ' + holders + ' (' + percentOf(g.count, holders) + '%)', programmeTooltipExtra()); });
            group.addEventListener('mousemove', function (e) { if (!tooltipEl.hidden) { tooltipEl.style.left = (e.clientX + 12) + 'px'; tooltipEl.style.top = (e.clientY + 12) + 'px'; } });
            group.addEventListener('mouseleave', hideTooltip);
            group.addEventListener('focus', function () { showTooltipNear(group, g.name, g.count + ' of ' + holders); });
            group.addEventListener('blur', hideTooltip);

            function activateProgramme(additive) {
                var current = highlight.programme || [];
                var isSame = current.length === g.names.length && g.names.every(function (n) { return current.indexOf(n) !== -1; });
                if (additive) {
                    var next = current.slice();
                    g.names.forEach(function (n) { var idx = next.indexOf(n); if (idx === -1) next.push(n); else next.splice(idx, 1); });
                    highlight.programme = next;
                } else {
                    highlight.programme = isSame ? [] : g.names.slice();
                }
                onHighlightChange();
            }
            group.addEventListener('click', function (e) { activateProgramme(e.ctrlKey || e.metaKey || e.shiftKey); });
            group.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activateProgramme(e.ctrlKey || e.metaKey || e.shiftKey); } });

            programmesCache.rows[g.name] = { seg: seg, names: g.names, count: g.count, contextPx: contextPx, valueLabel: valueLabel };
        });

        var captionText = 'Bar length = share of seat holders · Others = programmes outside the top 8.' + (scrolls ? ' Scroll for more →' : '');
        container.appendChild(el('p', { class: 'wsi-legend-caption' }, captionText));

        paintProgrammes();
    }

    function paintProgrammes() {
        if (!programmesCache) return;
        Object.keys(programmesCache.rows).forEach(function (name) {
            var info = programmesCache.rows[name];
            var highlightedCount = population.filter(function (r) { return r.programme != null && info.names.indexOf(r.programme) !== -1 && AGG.rowMatches(r, highlight); }).length;
            var ratio = info.count > 0 ? highlightedCount / info.count : 0;
            info.seg.solid.setAttribute('width', info.contextPx * ratio);
            info.valueLabel.style.opacity = (!anyHighlightActive() || highlightedCount > 0) ? '1' : '0.4';
        });
    }

    // =====================================================================
    // Generic vertical column chart (used by Candidacy expiry + Length of stay)
    // =====================================================================
    function buildColumnChart(containerId, dim, order, labelMap, colorMap, ariaLabel) {
        var container = document.getElementById(containerId);
        clear(container);
        var cache = { cols: {} };

        var context = AGG.context(population, dim);
        var total = order.reduce(function (s, k) { return s + (context[k] || 0); }, 0);

        if (total === 0) {
            container.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match these filters.'));
            return cache;
        }

        var width = container.clientWidth || 400;
        // Narrow cards (phones): tighter gutter, gap and column floor, so four columns fit without a sideways scroll (same fix as the Programme chart).
        var narrowCard = width < 420;
        var leftGutter = narrowCard ? 30 : 40, labelHeadroom = 24;
        var chartH = 220;
        var colGap = narrowCard ? 10 : 24;
        var colW = Math.max(narrowCard ? 28 : 36, Math.min(56, (width - leftGutter - 20) / order.length - colGap));
        // colW has its own 36px floor (PART 5's own minimum column
        // width) -- on a narrow container that floor can need MORE
        // space than the container actually has. Found live: the last
        // column's centred label spilled past the SVG's own right edge
        // when that happened. The SVG itself must be sized to what the
        // columns actually need, with the card scrolling horizontally
        // (never the page) if that is wider than the container.
        var requiredWidth = leftGutter + order.length * (colW + colGap) + 20; // +20: a centred 2-line label can overhang its own column slightly
        var svgWidth = Math.max(width, requiredWidth);
        var scrolls = svgWidth > width;
        if (scrolls) container.classList.add('wsi-hscroll');

        var maxVal = Math.max.apply(null, [5].concat(order.map(function (k) { return context[k] || 0; })));
        var scale = niceAxisMax(maxVal);

        var svg = svgEl('svg', { width: svgWidth, height: chartH + 40, role: 'img', 'aria-label': ariaLabel });

        scale.ticks.forEach(function (t) {
            var y = chartH - labelHeadroom - (t / scale.max) * (chartH - labelHeadroom - 16);
            svg.appendChild(svgEl('line', { x1: leftGutter, x2: svgWidth, y1: y, y2: y, stroke: COLORS.grid }));
            var lab = svgEl('text', { x: leftGutter - 8, y: y + 4, 'text-anchor': 'end', class: 'wsi-axis-label' });
            lab.textContent = String(t);
            svg.appendChild(lab);
        });

        order.forEach(function (key, i) {
            var x = leftGutter + i * (colW + colGap) + colGap / 2;
            var baseline = chartH - labelHeadroom;
            var usableH = baseline - 16;
            var contextCount = context[key] || 0;
            var h = scale.max > 0 ? (contextCount / scale.max) * usableH : 0;

            var group = svgEl('g', {});
            var ghost = svgEl('rect', { x: x, y: baseline - h, width: colW, height: h, fill: colorMap[key], 'fill-opacity': '0.2', class: 'wsi-ghost-rect' });
            var solid = svgEl('rect', { x: x, y: baseline - h, width: colW, height: h, fill: colorMap[key], class: 'wsi-solid-rect' });
            solid.style.setProperty('--wsi-mark-hover-fill', hoverColorFor(colorMap[key]));
            [ghost, solid].forEach(function (r) { r.style.transition = 'opacity ' + TRANSITION_MS + 'ms ease, fill 120ms ease'; });
            solid.style.pointerEvents = 'none';
            group.appendChild(ghost);
            group.appendChild(solid);
            svg.appendChild(group);

            var valueLabel = svgEl('text', { x: x + colW / 2, y: baseline - h - 6, 'text-anchor': 'middle', class: 'wsi-value-label' });
            valueLabel.textContent = String(contextCount);
            svg.appendChild(valueLabel);

            var lines = splitLabelTwoLines(narrowCard ? String(labelMap[key]).replace(' months', ' mo') : labelMap[key]);
            lines.forEach(function (line, li) {
                var t = svgEl('text', { x: x + colW / 2, y: chartH + 4 + li * 13, 'text-anchor': 'middle', class: 'wsi-category-label' });
                t.textContent = line;
                svg.appendChild(t);
            });

            // Hit target lives inside the same group as ghost/solid (see
            // the Rooms/Programmes charts' own comment on this pattern).
            var hit = svgEl('rect', { x: x, y: 0, width: colW, height: chartH, fill: 'transparent' });
            group.appendChild(hit);
            wireClickable(group, dim, key, function () { return [labelMap[key], contextCount + ' seat holders']; });

            cache.cols[key] = { solid: solid, baseline: baseline, h: h, contextCount: contextCount, label: valueLabel };
        });

        container.appendChild(svg);
        cache.scrolls = scrolls;
        return cache;
    }

    function paintColumnChart(cache, dim) {
        if (!cache) return;
        Object.keys(cache.cols).forEach(function (key) {
            var info = cache.cols[key];
            var highlightedCount = population.filter(function (r) { return AGG.rowValueForDim(r, dim) === key && AGG.rowMatches(r, highlight); }).length;
            var ratio = info.contextCount > 0 ? highlightedCount / info.contextCount : 0;
            info.solid.setAttribute('height', info.h * ratio);
            info.solid.setAttribute('y', info.baseline - info.h * ratio);
            info.label.style.opacity = (!anyHighlightActive() || highlightedCount > 0) ? '1' : '0.4';
        });
    }

    var candidacyCache = null, stayCache = null;

    function buildCandidacy() {
        candidacyCache = buildColumnChart('wsi-candidacy', 'expiry', EXPIRY_ORDER, EXPIRY_LABEL, EXPIRY_COLOR, 'Candidacy expiry of seat holders');
        var container = document.getElementById('wsi-candidacy');
        var context = AGG.context(population, 'expiry');
        var expiryLegendLabel = { '0_3': '0-3 months (urgent)', '3_6': '3-6 months', '6_12': '6-12 months', '12_plus': 'Over 12 months' };
        buildLegend(container, EXPIRY_ORDER.map(function (k) {
            return { dim: 'expiry', value: k, color: EXPIRY_COLOR[k], label: expiryLegendLabel[k], count: context[k] || 0 };
        }), candidacyCache.scrolls ? 'Scroll for more →' : null);
    }
    function buildStay() {
        var stayColor = {};
        STAY_ORDER.forEach(function (k) { stayColor[k] = COLORS.blue; });
        stayCache = buildColumnChart('wsi-stay', 'stay', STAY_ORDER, STAY_LABEL, stayColor, 'Length of stay of seat holders');
        var container = document.getElementById('wsi-stay');
        var captionText = 'Columns = seat holders by time since allocation.' + (stayCache.scrolls ? ' Scroll for more →' : '');
        container.appendChild(el('p', { class: 'wsi-legend-caption' }, captionText));
    }

    // =====================================================================
    // Round 8 PART 1.1: LOCKER KEY STATUS -- now a grouped bar chart,
    // same geometry as the other two. Groups = Block J2 / Block N2; bars
    // = Not requested / Pending collection / Collected / Return pending,
    // counted and percented against that BLOCK's own seat holders. A bar
    // click highlights the key status alone; a group-header click
    // highlights the block.
    // =====================================================================
    var keysCache = null;
    var KEY_BARS = KEY_ORDER.map(function (k) { return { key: k, label: KEY_LABEL[k], color: KEY_COLOR[k] }; });

    function keysGroups() {
        return BLOCKS.map(function (block) {
            var rows = population.filter(function (r) { return r.block === block; });
            var holders = rows.filter(function (r) { return r.key !== 'none'; }).length;
            return {
                key: block, rows: rows,
                headerText: 'Block ' + block,
                headerMeta: holders + ' seat holder' + (holders === 1 ? '' : 's'),
                headerDim: 'block', headerValue: block, headerUnit: ' seats',
            };
        });
    }

    function buildKeys() {
        var groups = keysGroups();
        var container = document.getElementById('wsi-keys');
        var grandTotal = population.filter(function (r) { return r.key !== 'none'; }).length;

        if (grandTotal === 0) {
            clear(container);
            container.appendChild(el('p', { class: 'wsi-empty' }, 'No seats match these filters.'));
            keysCache = null;
            return;
        }

        keysCache = buildGroupedBarChart('wsi-keys', 'Locker key status of seat holders, by block', groups, KEY_BARS, {
            countFor: function (g, b) { return g.rows.filter(function (r) { return r.key === b.key; }).length; },
            totalFor: function (g) { return g.rows.filter(function (r) { return r.key !== 'none'; }).length; },
            barPressed: function (g, b) { return isSelected('key', b.key); },
            highlightedCountFor: function (g, b) { return g.rows.filter(function (r) { return r.key === b.key && AGG.rowMatches(r, highlight); }).length; },
            onBarClick: function (g, b, additive) {
                highlight = AGG.toggleFilter(highlight, 'key', b.key, additive);
                onHighlightChange();
            },
            tooltipFor: function (g, b, count) { return [b.label, count + ' of ' + g.rows.filter(function (r) { return r.key !== 'none'; }).length + ' holders in ' + g.headerText]; },
        });

        // Round 8 PART 1.1: "Under the title a one-line total" -- at the
        // TOP of this card-body, before the chart itself, not after it.
        container.insertBefore(el('p', { class: 'wsi-footer-note' }, grandTotal + ' seat holder' + (grandTotal === 1 ? '' : 's') + ' in total.'), container.firstChild);

        var context = AGG.context(population, 'key');
        buildLegend(container, KEY_ORDER.map(function (k) {
            return { dim: 'key', value: k, color: KEY_COLOR[k], label: KEY_LABEL[k], count: context[k] || 0 };
        }));

        paintKeys();
    }

    function paintKeys() {
        paintGroupedBarChart(keysCache, keysGroups(), KEY_BARS, {
            highlightedCountFor: function (g, b) { return g.rows.filter(function (r) { return r.key === b.key && AGG.rowMatches(r, highlight); }).length; },
        });
    }

    // =====================================================================
    // 4.8b KEY RETURNS COMING UP (three tiles)
    // =====================================================================
    function buildReturns() {
        var container = document.getElementById('wsi-returns');
        clear(container);

        var defs = [
            { key: 'overdue', label: 'Overdue', color: COLORS.red },
            { key: 'due_7', label: 'Due within 7 days', color: COLORS.orange },
            { key: 'due_8_14', label: 'Due in 8-14 days', color: COLORS.gold },
        ];

        var wrap = el('div', { class: 'wsi-tile-row' });

        defs.forEach(function (def) {
            var count = population.filter(function (r) { return r.return_due === def.key; }).length;
            var highlightedCount = population.filter(function (r) { return r.return_due === def.key && AGG.rowMatches(r, highlight); }).length;
            var active = anyHighlightActive();
            var shown = active ? highlightedCount : count;

            var tile = el('button', { type: 'button', class: 'wsi-tile' });
            tile.style.borderTopColor = def.color;

            var value = el('div', { class: 'wsi-tile-value wsi-tabular' + (def.key === 'overdue' && shown > 0 ? ' wsi-tile-value-urgent' : '') }, String(shown));
            tile.appendChild(value);

            // Round 7 PART 4.4: the "of N" annotation gets its own line
            // consistently (never wraps mid-number) and is hidden below
            // 480px entirely rather than wrapping awkwardly.
            if (active && shown !== count) {
                tile.appendChild(el('span', { class: 'wsi-tile-ghost' }, 'of ' + count));
            }

            tile.appendChild(el('div', { class: 'wsi-tile-label' }, def.label));

            wireClickable(tile, 'due', def.key, function () { return [def.label, count + ' seats']; });
            wrap.appendChild(tile);
        });

        container.appendChild(wrap);
        container.appendChild(el('p', { class: 'wsi-kpi-key' }, 'Red = overdue · orange = due within 7 days · gold = due in 8-14 days.'));

        var link = el('a', { class: 'wsi-link wsi-footer-link', href: landingUrl({ key_status: 'overdue' }) }, 'View Key Pending Return list →');
        link.style.display = 'inline-block';
        container.appendChild(link);
    }

    function paintReturns() {
        buildReturns(); // cheap enough (3 tiles) that a rebuild-on-highlight is fine and simpler than a bespoke cache
    }

    // =====================================================================
    // RENDER ORCHESTRATION
    // =====================================================================
    function onFilterChange() {
        recomputePopulation();
        selectedPage = 1;
        renderDropdowns();
        renderAll();
        writeStateToUrl();
    }

    function onHighlightChange() {
        repaintHighlightOnly();
        refreshAriaPressed();
        renderChipsAndSummary();
        renderKpiRow();
        renderSelectedPanel();
        maybeShowDrillButton();
        writeStateToUrl();
    }

    function renderAll() {
        MARKS = [];
        renderChipsAndSummary();
        renderKpiRow();
        buildStatusByBlock();
        buildForecast();
        buildRooms();
        buildGender();
        buildProgrammes();
        buildCandidacy();
        buildStay();
        buildKeys();
        buildReturns();
        renderSelectedPanel();
        maybeShowDrillButton();
        refreshAriaPressed();
    }

    function repaintHighlightOnly() {
        paintStatusByBlock();
        paintForecast(drillPath.length ? drilledPeriods() : currentForecastData());
        paintRooms();
        paintGender();
        paintProgrammes();
        paintColumnChart(candidacyCache, 'expiry');
        paintColumnChart(stayCache, 'stay');
        paintKeys();
        paintReturns();
    }

    // =====================================================================
    // RESIZE
    // =====================================================================
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderAll, 150);
    });

    // =====================================================================
    // INIT
    // =====================================================================
    readStateFromUrl();
    recomputePopulation();
    renderDropdowns();
    wireDropdowns();
    renderAll();

    // =====================================================================
    // LIVE UPDATES
    //
    // The page polls a cheap data version every 15 s (only while visible
    // and focused -- window.ChloeLive in chloe-workstation-live.js). When
    // it changes, the new fact rows + meta are fetched and swapped in, and
    // the page is re-rendered through the SAME path a filter change uses,
    // so the dropdown filters, the highlight selection, the grain and the
    // drill-down all stay (they are plain state, never reset here). Charts
    // glide from their old geometry to the new one over TRANSITION_MS.
    // =====================================================================
    var GEOMETRY_ATTRS = ['x', 'y', 'width', 'height'];
    var CHART_IDS = ['wsi-status-by-block', 'wsi-forecast', 'wsi-rooms', 'wsi-gender', 'wsi-programmes', 'wsi-candidacy', 'wsi-stay', 'wsi-keys', 'wsi-returns'];

    /** {containerId: [{x,y,width,height} per <rect>, in DOM order]} */
    function snapshotGeometry() {
        var snap = {};
        CHART_IDS.forEach(function (id) {
            var c = document.getElementById(id);
            if (!c) return;
            snap[id] = Array.prototype.map.call(c.querySelectorAll('rect'), function (r) {
                var o = {};
                GEOMETRY_ATTRS.forEach(function (a) { o[a] = r.getAttribute(a); });
                return o;
            });
        });
        return snap;
    }

    /** Rebuilt rects start at their old geometry, then move to the new one. */
    function animateFromSnapshot(snap) {
        if (!TRANSITION_MS) return;
        var moved = [];
        CHART_IDS.forEach(function (id) {
            var c = document.getElementById(id);
            if (!c || !snap[id]) return;
            Array.prototype.forEach.call(c.querySelectorAll('rect'), function (r, i) {
                var old = snap[id][i];
                if (!old) return;
                var target = {};
                var differs = false;
                GEOMETRY_ATTRS.forEach(function (a) {
                    target[a] = r.getAttribute(a);
                    if (old[a] !== null && old[a] !== target[a]) differs = true;
                });
                if (!differs) return;
                var previousTransition = r.style.transition;
                r.style.transition = 'none';
                GEOMETRY_ATTRS.forEach(function (a) { if (old[a] !== null) r.setAttribute(a, old[a]); });
                moved.push({ rect: r, target: target, transition: previousTransition });
            });
        });
        if (!moved.length) return;
        void document.body.offsetWidth; // commit the old geometry before moving
        moved.forEach(function (m) {
            m.rect.style.transition = GEOMETRY_ATTRS.map(function (a) { return a + ' ' + TRANSITION_MS + 'ms ease'; }).join(', ') + ', opacity ' + TRANSITION_MS + 'ms ease, fill 120ms ease';
            GEOMETRY_ATTRS.forEach(function (a) { if (m.target[a] !== null) m.rect.setAttribute(a, m.target[a]); });
        });
        setTimeout(function () { moved.forEach(function (m) { m.rect.style.transition = m.transition; }); }, TRANSITION_MS + 60);
    }

    /** A drill-down whose period no longer exists falls back one level at a time. Returns true if it had to. */
    function repairDrillPath() {
        var fellBack = false;
        while (drillPath.length) {
            var top = drillPath[drillPath.length - 1];
            var saved = drillPath;
            drillPath = saved.slice(0, -1);
            var parentPeriods = drillPath.length ? drilledPeriods().periods : currentForecastData().periods;
            drillPath = saved;
            var exists = parentPeriods.some(function (p) { return p.key === top.key; });
            if (exists) break;
            drillPath = saved.slice(0, -1);
            fellBack = true;
        }
        return fellBack;
    }

    function showLiveNote(text) {
        var note = document.getElementById('wsi-live-note');
        if (!note) return;
        note.textContent = text;
        note.hidden = false;
        setTimeout(function () { note.hidden = true; }, 6000);
    }

    function applyLiveData(newRows, newMeta) {
        var snap = snapshotGeometry();

        ROWS = newRows;
        META = newMeta;
        OCCUPIED_ROWS = ROWS.filter(function (r) { return r.occupied; });
        ROOM_BY_CODE = {};
        (META.rooms || []).forEach(function (r) { ROOM_BY_CODE[r.code] = r; });
        BLOCKS = unique((META.rooms || []).map(function (r) { return r.block; })).sort();
        ALMOST_FULL_PERCENT = META.almost_full_percent || 90;

        recomputePopulation();
        var fellBack = repairDrillPath();
        renderDropdowns(); // keeps the selected filters (drops one only if its value vanished)
        renderAll();
        animateFromSnapshot(snap);
        writeStateToUrl();

        if (fellBack) showLiveNote('The period you drilled into no longer exists, so the chart went back one level.');
    }

    (function wireLive() {
        var cfg = document.getElementById('wsi-live-config');
        var L = window.ChloeLive;
        var text = document.getElementById('wsi-live-text');
        var dot = document.getElementById('wsi-live-dot');
        var pauseBtn = document.getElementById('wsi-live-pause');
        if (!cfg || !L || !text || !dot || !pauseBtn) return;

        var dataUrl = cfg.getAttribute('data-data-url');
        var fetching = false;

        function stamp(serverTime) {
            text.textContent = 'Live \u00b7 updated ' + serverTime;
        }

        function fetchData() {
            if (fetching) return;
            fetching = true;
            fetch(dataUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (res) { return res.ok ? res.json() : null; })
                .then(function (json) {
                    if (json && Array.isArray(json.rows) && json.meta) {
                        applyLiveData(json.rows, json.meta);
                        stamp(json.server_time);
                    }
                })
                .catch(function () { /* the next change tries again */ })
                .then(function () { fetching = false; });
        }

        var poller = L.createPoller({
            versionUrl: cfg.getAttribute('data-version-url'),
            interval: parseInt(cfg.getAttribute('data-interval'), 10) || 15000,
            version: cfg.getAttribute('data-version'),
            onTick: function (json) {
                dot.classList.remove('wsi-live-error');
                if (!poller.isPaused()) stamp(json.server_time);
            },
            onChange: fetchData,
            onStop: function () { dot.classList.add('wsi-live-error'); text.textContent = 'Live updates stopped \u00b7 sign in again and refresh'; },
        });
        window.ChloeInsightsLive = { poller: poller, applyLiveData: applyLiveData };

        pauseBtn.addEventListener('click', function () {
            if (poller.isPaused()) {
                poller.resume();
                pauseBtn.textContent = 'Pause updates';
                dot.classList.remove('wsi-live-paused');
            } else {
                poller.pause();
                pauseBtn.textContent = 'Resume updates';
                dot.classList.add('wsi-live-paused');
                text.textContent = 'Paused';
            }
        });

        poller.start();
    })();
})();
