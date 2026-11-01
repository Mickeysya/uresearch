/* Candidacy Insights: operational monitoring of the known expiry dates (a projection, not a prediction).
 * The server embeds flat rows (Support\CandidacyStatistics); everything here only tallies them, so filters and Power BI-style highlighting recompute every card and the student list.
 * Same markup, classes, colours and mechanisms as Workstation Insights (chloe-workstation-insights.js); no chart library. */
(function () {
    'use strict';

    var root = document.getElementById('wsi-page');
    if (!root) { return; }

    var DATA = JSON.parse(document.getElementById('wci-data').textContent);
    var LIVE = document.getElementById('wsi-live-config').dataset;
    var NS = 'http://www.w3.org/2000/svg';
    var C = { blue: '#004B85', blue2: '#0066B3', gold: '#C88A12', orange: '#F68B33', red: '#C62828', green: '#2E8B57', slate: '#606C73' };
    var CAT_ORDER = ['softbound', 'in_review', 'returned', 'none'];
    var CAT_COLOR = { softbound: C.green, in_review: C.blue, returned: C.gold, none: C.red };
    var BAND_ORDER = ['m1', 'm2', 'm3'];
    var MILESTONE_COLOR = { 3: C.blue, 2: C.gold, 1: C.orange };
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var filters = { dept: '', level: '', mode: '', category: '' };
    var HL_DIMS = ['category', 'band', 'period', 'remMonth', 'milestone', 'dismissedMonth'];
    var highlight = emptyHighlight();
    var tableState = { q: '', page: 1 };
    var charts = []; // {id, paint: function}

    function emptyHighlight() { var h = {}; HL_DIMS.forEach(function (d) { h[d] = []; }); return h; }

    // ---------------------------------------------------------------- helpers
    function el(tag, attrs, text) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (text != null) { n.textContent = text; }
        return n;
    }
    function svgEl(tag, attrs) {
        var n = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }
    function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }
    function sum(a) { return a.reduce(function (x, y) { return x + y; }, 0); }
    function monthLabel(ym) { return MONTHS[parseInt(ym.slice(5, 7), 10) - 1] + ' ' + ym.slice(0, 4); }
    function monthShort(ym) { return MONTHS[parseInt(ym.slice(5, 7), 10) - 1]; }
    function niceMax(v) { if (v <= 5) { return 5; } var p = Math.pow(10, Math.floor(Math.log10(v))); var n = Math.ceil(v / p); return (n <= 2 ? 2 : n <= 5 ? 5 : 10) * p; }
    function levelName(l) { return l === 'phd' ? 'PhD' : 'Master'; }
    function modeName(m) { return m === 'part' ? 'Part-Time' : 'Full-Time'; }
    function deptName(code) { var d = DATA.departments.filter(function (x) { return x.code === code; })[0]; return d ? d.name : (code || '—'); }
    function fmtDate(iso) { var p = iso.split('-'); return parseInt(p[2], 10) + ' ' + MONTHS[parseInt(p[1], 10) - 1] + ' ' + p[0]; }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    // ---------------------------------------------------------------- tooltip
    var tooltipEl = document.getElementById('wsi-tooltip');
    function showTooltip(x, y, title, lines) {
        clear(tooltipEl);
        tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-title' }, title));
        (lines || []).forEach(function (l) { tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-value' }, l)); });
        tooltipEl.appendChild(el('div', { class: 'wsi-tooltip-sub' }, 'Click to highlight · Ctrl+click to select more'));
        tooltipEl.hidden = false;
        var left = x + 12, top = y + 12;
        tooltipEl.style.left = left + 'px'; tooltipEl.style.top = top + 'px';
        var r = tooltipEl.getBoundingClientRect();
        if (r.right > window.innerWidth) { tooltipEl.style.left = Math.max(4, x - r.width - 12) + 'px'; }
        if (r.bottom > window.innerHeight) { tooltipEl.style.top = Math.max(4, y - r.height - 12) + 'px'; }
    }
    function showTooltipNear(target, title, lines) { var r = target.getBoundingClientRect(); showTooltip(r.left + r.width / 2, r.top, title, lines); }
    function hideTooltip() { tooltipEl.hidden = true; }
    window.addEventListener('scroll', hideTooltip, true);
    window.addEventListener('resize', hideTooltip);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideTooltip(); } });

    // ---------------------------------------------------------------- filters and highlight
    function ctxRows() {
        return DATA.rows.filter(function (r) {
            return (!filters.dept || r.dept === filters.dept) && (!filters.level || r.level === filters.level)
                && (!filters.mode || r.mode === filters.mode) && (!filters.category || r.category === filters.category);
        });
    }
    function demographic(r) { return (!filters.dept || r.dept === filters.dept) && (!filters.level || r.level === filters.level) && (!filters.mode || r.mode === filters.mode); }
    function hlActive() { return HL_DIMS.some(function (d) { return highlight[d].length; }); }
    function periodKeys() { return highlight.period; } // month keys (YYYY-MM)
    function rowHighlighted(r) {
        if (highlight.category.length && highlight.category.indexOf(r.category) === -1) { return false; }
        if (highlight.band.length && highlight.band.indexOf(r.band) === -1) { return false; }
        if (highlight.period.length && highlight.period.indexOf(r.month) === -1) { return false; }
        if (highlight.remMonth.length || highlight.milestone.length) {
            var ok = r.reminders.some(function (x) {
                return (!highlight.remMonth.length || highlight.remMonth.indexOf((x.d || '').slice(0, 7)) !== -1) && (!highlight.milestone.length || highlight.milestone.indexOf(x.m) !== -1);
            });
            if (!ok) { return false; }
        }
        return true;
    }
    function hlRows(rows) { return hlActive() ? rows.filter(rowHighlighted) : rows; }
    function toggle(dim, value, additive) {
        var cur = highlight[dim].slice(), i = cur.indexOf(value);
        if (additive) { if (i === -1) { cur.push(value); } else { cur.splice(i, 1); } }
        else { cur = (i !== -1 && cur.length === 1) ? [] : [value]; }
        highlight[dim] = cur;
        onChange();
    }
    function setHighlight(dim, values) {
        var cur = highlight[dim], same = cur.length === values.length && values.every(function (v) { return cur.indexOf(v) !== -1; });
        highlight[dim] = same ? [] : values.slice();
        onChange();
    }
    function isOn(dim, value) { return highlight[dim].indexOf(value) !== -1; }

    /** Makes a mark a keyboard-operable button for (dim, value); hover and focus show the tooltip. */
    function wire(target, dim, value, tip, onClickValues) {
        target.setAttribute('tabindex', '0'); target.setAttribute('role', 'button');
        target.setAttribute('aria-pressed', isOn(dim, value) ? 'true' : 'false');
        target.classList.add('wsi-mark');
        var act = function (additive) { if (onClickValues) { onClickValues(additive); } else { toggle(dim, value, additive); } };
        target.addEventListener('click', function (e) { act(e.ctrlKey || e.metaKey || e.shiftKey); });
        target.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(e.ctrlKey || e.metaKey || e.shiftKey); } });
        target.addEventListener('mouseenter', function (e) { var t = tip(); showTooltip(e.clientX, e.clientY, t[0], t.slice(1)); });
        target.addEventListener('mousemove', function (e) { if (!tooltipEl.hidden) { tooltipEl.style.left = (e.clientX + 12) + 'px'; tooltipEl.style.top = (e.clientY + 12) + 'px'; } });
        target.addEventListener('mouseleave', hideTooltip);
        target.addEventListener('focus', function () { var t = tip(); showTooltipNear(target, t[0], t.slice(1)); });
        target.addEventListener('blur', hideTooltip);
    }

    function legend(container, entries, caption) {
        var lg = el('div', { class: 'wsi-legend' });
        entries.forEach(function (e) {
            var sw;
            if (e.kind === 'line') { sw = el('span', { class: 'wsi-swatch-line' }); sw.style.background = e.color; }
            else { sw = el('span', { class: 'wsi-swatch' }); sw.style.background = e.color; }
            var item = el(e.dim ? 'button' : 'span', e.dim ? { type: 'button', class: 'wsi-legend-item' } : { class: 'wsi-legend-item wsi-legend-static' });
            item.appendChild(sw); item.appendChild(document.createTextNode(e.label + (e.count != null ? ' · ' + e.count : '')));
            if (e.dim) { wire(item, e.dim, e.value, function () { return [e.label, e.count != null ? String(e.count) : '']; }); }
            lg.appendChild(item);
        });
        container.appendChild(lg);
        if (caption) { container.appendChild(el('p', { class: 'wsi-legend-caption' }, caption)); }
    }

    // ---------------------------------------------------------------- stacked column chart (forecast, bands, reminders, dismissals)
    /**
     * cfg: {id, label, groups:[{key,label,short,segs:{name:count},solid:{name:count}|null,tip:[...],dim,value,onClick}], order:[names], colors:{name:color}, line:[numbers]|null, lineColor, height}
     * Ghost = the context (all filtered rows); solid = the highlighted part, drawn on top from the baseline.
     */
    function stackedColumns(container, cfg) {
        clear(container);
        var width = Math.max(240, container.clientWidth || 600), height = cfg.height || 260;
        var padL = 34, padR = (cfg.line && !cfg.sharedAxis) ? 34 : 8, padT = 10, padB = 46;
        var plotW = width - padL - padR, plotH = height - padT - padB;
        var totals = cfg.groups.map(function (g) { return sum(cfg.order.map(function (k) { return g.segs[k] || 0; })); });
        var maxV = niceMax(Math.max.apply(null, [1].concat(totals, cfg.sharedAxis && cfg.line ? cfg.line : [])));
        var svg = svgEl('svg', { width: width, height: height, role: 'img', 'aria-label': cfg.label });
        container.appendChild(svg);
        var y = function (v) { return padT + plotH - (v / maxV) * plotH; };
        for (var t = 0; t <= 4; t++) {
            var v = maxV * t / 4, gy = y(v);
            svg.appendChild(svgEl('line', { x1: padL, x2: width - padR, y1: gy, y2: gy, stroke: '#E9E9E1', 'stroke-width': 1 }));
            var tl = svgEl('text', { x: padL - 6, y: gy + 4, 'text-anchor': 'end', class: 'wsi-axis-label' }); tl.textContent = Math.round(v); svg.appendChild(tl);
        }
        var n = cfg.groups.length, slot = plotW / Math.max(1, n), bw = Math.min(46, slot * 0.66);
        var active = hlActive();
        cfg.groups.forEach(function (g, i) {
            var x = padL + slot * i + (slot - bw) / 2, grp = svgEl('g', {});
            [['ghost', g.segs, active ? 0.22 : 1], ['solid', g.solid, 1]].forEach(function (pass) {
                if (!pass[1]) { return; }
                var acc = 0;
                cfg.order.forEach(function (k) {
                    var cnt = pass[1][k] || 0; if (!cnt) { return; }
                    var r = svgEl('rect', { x: x, y: y(acc + cnt), width: bw, height: Math.max(0, y(acc) - y(acc + cnt)), fill: cfg.colors[k], 'fill-opacity': pass[2] });
                    grp.appendChild(r); acc += cnt;
                });
            });
            var hit = svgEl('rect', { x: padL + slot * i, y: padT, width: slot, height: plotH + padB - 6, fill: 'transparent' });
            grp.appendChild(hit);
            var narrow = slot < 30, lab = svgEl('text', { x: x + bw / 2, y: height - padB + 16, 'text-anchor': 'middle', class: 'wsi-category-label' }); lab.textContent = narrow ? (g.narrow || (g.short || g.label).slice(0, 1)) : (g.short || g.label); svg.appendChild(lab);
            if (g.sub) { var sub = svgEl('text', { x: x + bw / 2, y: height - padB + 30, 'text-anchor': 'middle', class: 'wsi-axis-label' }); sub.textContent = (narrow && /^\d{4}$/.test(g.sub)) ? "'" + g.sub.slice(2) : g.sub; svg.appendChild(sub); }
            if (totals[i] > 0 && slot >= 20) { var tt = svgEl('text', { x: x + bw / 2, y: y(totals[i]) - 4, 'text-anchor': 'middle', class: 'wsi-value-label' }); tt.textContent = totals[i]; svg.appendChild(tt); }
            svg.appendChild(grp);
            wire(grp, g.dim, g.value, function () { return g.tip; }, g.onClick);
        });
        if (cfg.line) {
            var maxL = cfg.sharedAxis ? maxV : niceMax(Math.max.apply(null, [1].concat(cfg.line)));
            var yl = function (v) { return padT + plotH - (v / maxL) * plotH; };
            var pts = cfg.line.map(function (v, i) { return (padL + slot * i + slot / 2) + ',' + yl(v); }).join(' ');
            svg.appendChild(svgEl('polyline', { points: pts, fill: 'none', stroke: cfg.lineColor || C.slate, 'stroke-width': 2, 'pointer-events': 'none' }));
            cfg.line.forEach(function (v, i) { svg.appendChild(svgEl('circle', { cx: padL + slot * i + slot / 2, cy: yl(v), r: 3, fill: cfg.lineColor || C.slate, 'pointer-events': 'none' })); });
            if (!cfg.sharedAxis) { [0, maxL / 2, maxL].forEach(function (v) { var tl2 = svgEl('text', { x: width - padR + 4, y: yl(v) + 4, class: 'wsi-axis-label' }); tl2.textContent = Math.round(v); svg.appendChild(tl2); }); }
        }
        if (n === 0 || totals.every(function (z) { return z === 0; })) {
            var none = svgEl('text', { x: width / 2, y: padT + plotH / 2, 'text-anchor': 'middle', class: 'wsi-axis-label' }); none.textContent = cfg.emptyText || 'Nothing to show for these filters.'; svg.appendChild(none);
        }
    }
    function catSegs(rows) { var s = {}; CAT_ORDER.forEach(function (k) { s[k] = 0; }); rows.forEach(function (r) { s[r.category]++; }); return s; }
    function catLegend(container, rows) {
        var s = catSegs(rows);
        legend(container, CAT_ORDER.map(function (k) { return { label: DATA.categories[k], color: CAT_COLOR[k], count: s[k], dim: 'category', value: k }; }));
    }

    // ---------------------------------------------------------------- KPI cards
    function appealsCtx() { return DATA.appeals.filter(demographic); }
    function candidatesCtx() { return DATA.candidates.filter(demographic); }
    function kpiDefs() {
        var rows = ctxRows(), within = rows.filter(function (r) { return r.band; });
        return [
            { key: 'within3', label: 'Expiring within 3 months', border: 'wsi-kpi-orange', context: within.length, hl: hlRows(within).length, dim: 'band', values: BAND_ORDER },
            { key: 'high', label: 'No action yet (high risk)', border: 'wsi-kpi-red', context: rows.filter(function (r) { return r.category === 'none'; }).length, hl: hlRows(rows).filter(function (r) { return r.category === 'none'; }).length, dim: 'category', values: ['none'] },
            { key: 'progress', label: 'Appeals in progress', border: 'wsi-kpi-gold', context: appealsCtx().length, hl: hlRows(rows).filter(function (r) { return r.category === 'in_review'; }).length, dim: 'category', values: ['in_review'], view: LIVE.operationsUrl + '?group=in_progress', viewLabel: 'View in Operations' },
            { key: 'dismissal', label: 'Dismissal candidates', border: 'wsi-kpi-blue', context: candidatesCtx().length, hl: candidatesCtx().length, view: LIVE.dismissalsUrl, viewLabel: 'View the list' }
        ];
    }
    function renderKpis() {
        var row = document.getElementById('wsi-kpi-row'); clear(row);
        var active = hlActive();
        kpiDefs().forEach(function (d) {
            var card = el('button', { type: 'button', class: 'wsi-kpi-card ' + d.border });
            var shown = (active && d.dim) ? d.hl : d.context;
            var v = el('div', { class: 'wsi-kpi-value wsi-tabular' }, String(shown));
            if (active && d.dim && d.hl !== d.context) { v.appendChild(el('span', { class: 'wsi-kpi-value-ghost' }, 'of ' + d.context)); }
            card.appendChild(v); card.appendChild(el('div', { class: 'wsi-kpi-label' }, d.label));
            if (d.dim) {
                var pressed = d.values.every(function (x) { return isOn(d.dim, x); }) && highlight[d.dim].length === d.values.length;
                card.setAttribute('aria-pressed', pressed ? 'true' : 'false'); if (pressed) { card.classList.add('wsi-selected'); }
                card.addEventListener('click', function (e) { if (e.target.closest('a')) { return; } setHighlight(d.dim, d.values); });
            } else {
                card.addEventListener('click', function (e) { if (!e.target.closest('a')) { window.location.href = d.view; } });
            }
            if (d.view) { var w = el('div', {}); var a = el('a', { class: 'wsi-link wsi-kpi-view', href: d.view }, d.viewLabel + ' →'); a.style.display = 'inline'; a.addEventListener('click', function (e) { e.stopPropagation(); }); w.appendChild(a); card.appendChild(w); }
            row.appendChild(card);
        });
    }

    // ---------------------------------------------------------------- filter bar, chips, summary
    function renderFilterOptions() {
        var dept = document.getElementById('wci-f-dept'); clear(dept);
        dept.appendChild(el('option', { value: '' }, 'All departments'));
        DATA.departments.forEach(function (d) { dept.appendChild(el('option', { value: d.code }, d.name)); });
        var cat = document.getElementById('wci-f-category'); clear(cat);
        cat.appendChild(el('option', { value: '' }, 'All'));
        CAT_ORDER.forEach(function (k) { cat.appendChild(el('option', { value: k }, DATA.categories[k])); });
        syncFilterControls();
    }
    function syncFilterControls() { Object.keys(filters).forEach(function (k) { var s = root.querySelector('[data-filter="' + k + '"]'); if (s) { s.value = filters[k]; } }); }
    root.querySelectorAll('[data-filter]').forEach(function (s) { s.addEventListener('change', function () { filters[s.dataset.filter] = s.value; tableState.page = 1; onChange(); }); });
    document.getElementById('wsi-reset-filters').addEventListener('click', function () { filters = { dept: '', level: '', mode: '', category: '' }; syncFilterControls(); tableState.page = 1; onChange(); });
    document.getElementById('wsi-clear-all').addEventListener('click', function () { highlight = emptyHighlight(); tableState.page = 1; onChange(); });

    var CHIP = { category: function (v) { return DATA.categories[v]; }, band: function (v) { return DATA.bands[v]; }, period: function (v) { return monthLabel(v); }, remMonth: function (v) { return 'Reminders in ' + monthLabel(v); }, milestone: function (v) { return v + '-month reminder'; }, dismissedMonth: function (v) { return 'Dismissed in ' + monthLabel(v); } };
    function renderStrip() {
        var strip = document.getElementById('wsi-strip'), chips = document.getElementById('wsi-chip-strip'); clear(chips);
        var any = hlActive();
        strip.hidden = !any; document.getElementById('wsi-clear-all').hidden = !any;
        document.getElementById('wsi-strip-tip').hidden = any;
        HL_DIMS.forEach(function (d) {
            highlight[d].forEach(function (v) {
                var chip = el('span', { class: 'wsi-chip' }); chip.appendChild(el('span', { class: 'wsi-chip-text' }, CHIP[d](v)));
                var x = el('button', { type: 'button', class: 'wsi-chip-x', 'aria-label': 'Remove ' + CHIP[d](v) }, '×'); x.addEventListener('click', function () { toggle(d, v, true); });
                chip.appendChild(x); chips.appendChild(chip);
            });
        });
        var ctx = ctxRows();
        document.getElementById('wsi-summary').textContent = any ? 'Highlighting ' + hlRows(ctx).length + ' of ' + ctx.length + ' students' : 'Showing ' + ctx.length + ' of ' + DATA.rows.length + ' students';
    }

    // ---------------------------------------------------------------- render
    function onChange() { hideTooltip(); renderAll(); }
    function renderAll() { renderStrip(); renderKpis(); charts.forEach(function (c) { c.paint(); }); }
    // (chart builders are registered below, one stage at a time)
    //@@CHARTS@@
    // ================================================================ Chart 1: Candidacy expiry forecast (Year / Quarter / Month, drill down)
    var grain = 'month', drill = []; // drill = [{grain:'year', key:'2027'}, {grain:'quarter', key:'2027-Q2'}]
    function ymAdd(ym, n) { var y = parseInt(ym.slice(0, 4), 10), m = parseInt(ym.slice(5, 7), 10) - 1 + n; y += Math.floor(m / 12); m = ((m % 12) + 12) % 12; return y + '-' + ('0' + (m + 1)).slice(-2); }
    function quarterOf(ym) { return ym.slice(0, 4) + '-Q' + Math.ceil(parseInt(ym.slice(5, 7), 10) / 3); }
    function monthsOfQuarter(q) { var y = q.slice(0, 4), n = parseInt(q.slice(6), 10); return [0, 1, 2].map(function (i) { return y + '-' + ('0' + ((n - 1) * 3 + i + 1)).slice(-2); }); }
    function monthsOfYear(y) { var out = []; for (var i = 1; i <= 12; i++) { out.push(y + '-' + ('0' + i).slice(-2)); } return out; }
    function lastExpiryMonth() { var m = DATA.rows.reduce(function (a, r) { return r.month > a ? r.month : a; }, DATA.today.slice(0, 7)); return m; }
    function forecastPeriods() {
        var thisMonth = DATA.today.slice(0, 7), out = [], last = drill.length ? drill[drill.length - 1] : null;
        if (grain === 'month') {
            var months = last ? monthsOfQuarter(last.key) : [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11].map(function (i) { return ymAdd(thisMonth, i); });
            return months.map(function (m) { return { key: m, label: monthLabel(m), short: monthShort(m), sub: (m === months[0] || m.slice(5) === '01') ? m.slice(0, 4) : '', months: [m] }; });
        }
        if (grain === 'quarter') {
            var qs = [];
            if (last) { for (var i = 1; i <= 4; i++) { qs.push(last.key + '-Q' + i); } }
            else { var q = quarterOf(thisMonth), end = quarterOf(lastExpiryMonth()); var yy = parseInt(q.slice(0, 4), 10), qq = parseInt(q.slice(6), 10); for (var n = 0; n < 40; n++) { var key = yy + '-Q' + qq; qs.push(key); if (key >= end && qs.length >= 4) { break; } qq++; if (qq > 4) { qq = 1; yy++; } } }
            return qs.map(function (k) { return { key: k, label: 'Q' + k.slice(6) + ' ' + k.slice(0, 4), short: 'Q' + k.slice(6), sub: k.slice(0, 4), months: monthsOfQuarter(k) }; });
        }
        var ys = [], y0 = parseInt(thisMonth.slice(0, 4), 10), y1 = parseInt(lastExpiryMonth().slice(0, 4), 10);
        for (var y = y0; y <= Math.max(y1, y0); y++) { ys.push(String(y)); }
        return ys.map(function (k) { return { key: k, label: k, short: k, sub: '', months: monthsOfYear(k) }; });
    }
    function sameMonths(a, b) { return a.length === b.length && a.every(function (x) { return b.indexOf(x) !== -1; }); }
    function paintForecast() {
        var periods = forecastPeriods(), ctx = ctxRows(), hl = hlRows(ctx), active = hlActive();
        var rowsIn = function (rows, p) { return rows.filter(function (r) { return p.months.indexOf(r.month) !== -1; }); };
        var running = 0, line = periods.map(function (p) { running += rowsIn(ctx, p).length; return running; });
        var groups = periods.map(function (p) {
            var inCtx = rowsIn(ctx, p), segs = catSegs(inCtx), solid = active ? catSegs(rowsIn(hl, p)) : null, total = inCtx.length;
            var tip = [p.label, plural(total, 'student', 'students') + ' expire'].concat(CAT_ORDER.map(function (k) { return DATA.categories[k] + ': ' + segs[k] + (solid ? ' (' + solid[k] + ' highlighted)' : ''); }));
            return { key: p.key, label: p.label, short: p.short, sub: p.sub, segs: segs, solid: solid, tip: tip, dim: 'period', value: p.months[0],
                onClick: function (additive) {
                    if (!additive) { setHighlight('period', p.months); return; }
                    var all = p.months.every(function (m) { return isOn('period', m); });
                    p.months.forEach(function (m) { var i = highlight.period.indexOf(m); if (all && i !== -1) { highlight.period.splice(i, 1); } else if (!all && i === -1) { highlight.period.push(m); } });
                    onChange();
                } };
        });
        var box = document.getElementById('wci-forecast');
        stackedColumns(box, { label: 'Candidacy expiry forecast by period', groups: groups, order: CAT_ORDER, colors: CAT_COLOR, line: line, lineColor: C.slate, height: 300, emptyText: 'No candidacy expires in this period for these filters.' });
        catLegend(box, ctx); legend(box, [{ label: 'Running total of the periods shown', color: C.slate, kind: 'line' }]);
        // pressed state of the bars follows the highlighted months
        box.querySelectorAll('g.wsi-mark').forEach(function (g, i) { g.setAttribute('aria-pressed', periods[i].months.every(function (m) { return highlight.period.indexOf(m) !== -1; }) ? 'true' : 'false'); });
        document.querySelectorAll('#wci-grain button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.grain === grain ? 'true' : 'false'); });
        document.getElementById('wci-forecast-subtitle').textContent = ({ year: 'Year by year.', quarter: 'Quarter by quarter.', month: 'Month by month' + (drill.length ? ' in ' + drill[drill.length - 1].key + '.' : ', the next 12 months.') })[grain];
        // breadcrumb and the drill-down button
        var bc = document.getElementById('wci-breadcrumb'); clear(bc);
        var levels = ['year', 'quarter', 'month'], showCrumb = drill.length > 0;
        bc.hidden = !(showCrumb);
        if (showCrumb) {
            var all = el('button', { type: 'button', class: 'wsi-link' }, 'All years'); all.addEventListener('click', function () { drill = []; grain = 'year'; highlight.period = []; onChange(); });
            bc.appendChild(all);
            drill.forEach(function (d, i) { bc.appendChild(document.createTextNode(' › ')); if (i === drill.length - 1) { bc.appendChild(el('span', {}, d.key)); } else { var b2 = el('button', { type: 'button', class: 'wsi-link' }, d.key); b2.addEventListener('click', function () { drill = drill.slice(0, i + 1); grain = levels[i + 1]; highlight.period = []; onChange(); }); bc.appendChild(b2); } });
        }
        var old = box.querySelector('.wsi-drill-btn'); if (old) { old.remove(); }
        var sel = highlight.period.length ? periods.filter(function (p) { return p.months.every(function (m) { return highlight.period.indexOf(m) !== -1; }); }) : [];
        if (grain !== 'month' && sel.length === 1 && sameMonths(highlight.period, sel[0].months)) {
            var db = el('button', { type: 'button', class: 'wsi-drill-btn' }, 'Drill down into ' + sel[0].label + ' ›');
            db.addEventListener('click', function () { drill.push({ grain: grain, key: sel[0].key }); grain = grain === 'year' ? 'quarter' : 'month'; highlight.period = []; onChange(); });
            box.appendChild(db);
        }
    }
    document.querySelectorAll('#wci-grain button').forEach(function (b) { b.addEventListener('click', function () { grain = b.dataset.grain; drill = []; highlight.period = []; onChange(); }); });
    charts.push({ id: 'forecast', paint: paintForecast });

    // ================================================================ Chart 2: Expiring within 3 months + the student list
    function bandRows(rows, b) { return rows.filter(function (r) { return r.band === b; }); }
    function paintBands() {
        var ctx = ctxRows(), hl = hlRows(ctx), active = hlActive();
        var groups = BAND_ORDER.map(function (b) {
            var inCtx = bandRows(ctx, b), segs = catSegs(inCtx), solid = active ? catSegs(bandRows(hl, b)) : null;
            var tip = [DATA.bands[b], plural(inCtx.length, 'student', 'students')].concat(CAT_ORDER.map(function (k) { return DATA.categories[k] + ': ' + segs[k] + (solid ? ' (' + solid[k] + ' highlighted)' : ''); }));
            return { key: b, label: DATA.bands[b], short: DATA.bands[b].replace(' months', ' mo').replace(' month', ' mo'), narrow: DATA.bands[b].replace('Up to ', '≤').replace(' months', 'mo').replace(' month', 'mo'), segs: segs, solid: solid, tip: tip, dim: 'band', value: b };
        });
        var box = document.getElementById('wci-bands');
        stackedColumns(box, { label: 'Students expiring within 3 months by band and status category', groups: groups, order: CAT_ORDER, colors: CAT_COLOR, height: 240, emptyText: 'Nobody expires within 3 months for these filters.' });
        catLegend(box, ctx.filter(function (r) { return r.band; }));
    }
    charts.push({ id: 'bands', paint: paintBands });

    var PAGE = 10, PRIORITY = { none: 0, returned: 1, in_review: 2, softbound: 3 };
    function windowSlots(total, current, slots) {
        if (total < 1) { return []; }
        var all = function (a, b) { var o = []; for (var i = a; i <= b; i++) { o.push(i); } return o; };
        current = Math.max(1, Math.min(current, total));
        if (total <= slots) { return all(1, total); }
        var edge = slots - 2;
        if (current <= slots - 3) { return all(1, edge).concat(['…', total]); }
        if (current >= total - slots + 4) { return [1, '…'].concat(all(total - edge + 1, total)); }
        var inner = slots - 4, start = current - Math.floor((inner - 1) / 2);
        return [1, '…'].concat(all(start, start + inner - 1), ['…', total]);
    }
    function pagination(container, page, pages, total, noun, go) {
        if (total === 0) { container.appendChild(el('p', { class: 'wca-muted wca-small wca-showing' }, 'No ' + noun + ' found')); return; }
        if (pages > 1) {
            [['is-full', 7], ['is-compact', 5]].forEach(function (m) {
                var nav = el('nav', { class: 'cgs-pagination ' + m[0], 'aria-label': 'Pages' });
                var link = function (txt, n, rel) { var a = el('a', { href: '#' }, txt); if (rel) { a.setAttribute('rel', rel); } a.addEventListener('click', function (e) { e.preventDefault(); go(n); }); return a; };
                nav.appendChild(page <= 1 ? el('span', { class: 'is-disabled', 'aria-disabled': 'true' }, '« Prev') : link('« Prev', page - 1, 'prev'));
                windowSlots(pages, page, m[1]).forEach(function (s) {
                    if (s === '…') { var g = el('span', { class: 'is-gap', 'aria-hidden': 'true' }, '…'); nav.appendChild(g); }
                    else if (s === page) { nav.appendChild(el('span', { class: 'is-current', 'aria-current': 'page' }, String(s))); }
                    else { nav.appendChild(link(String(s), s)); }
                });
                nav.appendChild(page >= pages ? el('span', { class: 'is-disabled', 'aria-disabled': 'true' }, 'Next »') : link('Next »', page + 1, 'next'));
                container.appendChild(nav);
            });
        }
        var from = (page - 1) * PAGE + 1, to = Math.min(total, page * PAGE);
        container.appendChild(el('p', { class: 'wca-muted wca-small wca-showing' }, 'Showing ' + from + ' to ' + to + ' of ' + total + ' ' + (total === 1 ? noun.replace(/s$/, '') : noun)));
    }
    function listRows() {
        var q = tableState.q.trim().toLowerCase();
        return hlRows(ctxRows()).filter(function (r) { return r.band && (!q || (r.name + ' ' + r.studentId).toLowerCase().indexOf(q) !== -1); })
            .sort(function (a, b) { return a.daysLeft - b.daysLeft || PRIORITY[a.category] - PRIORITY[b.category] || a.name.localeCompare(b.name); });
    }
    function paintList() {
        var box = document.getElementById('wci-students'); clear(box);
        var rows = listRows(), pages = Math.max(1, Math.ceil(rows.length / PAGE));
        tableState.page = Math.min(Math.max(1, tableState.page), pages);
        if (rows.length === 0) { box.appendChild(el('p', { class: 'wsi-empty' }, 'No students expiring within 3 months match this selection.')); return; }
        var wrap = el('div', { class: 'wca-table-wrap' }), table = el('table', { class: 'wca-table is-stack is-compact' }), head = el('tr');
        ['Student', 'Department', 'Level', 'Expiry date', 'Days left', 'Status category', 'Appeal no.', 'Reminders sent', 'Last reminder'].forEach(function (h) { head.appendChild(el('th', {}, h)); });
        var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
        var tb = el('tbody');
        rows.slice((tableState.page - 1) * PAGE, tableState.page * PAGE).forEach(function (r) {
            var tr = el('tr'), td = function (label, content, cls) { var c = el('td', { 'data-label': label }); if (cls) { c.className = cls; } if (content instanceof Node) { c.appendChild(content); } else { c.textContent = content; } tr.appendChild(c); return c; };
            var who = el('span'); who.appendChild(document.createTextNode(r.name)); who.appendChild(el('br')); who.appendChild(el('span', { class: 'wca-muted wca-small' }, r.studentId));
            td('Student', who); td('Department', r.deptName || '—'); td('Level', levelName(r.level) + (r.mode === 'part' ? ' · Part-Time' : '')); td('Expiry date', fmtDate(r.expiry), 'wca-nowrap');
            td('Days left', String(r.daysLeft), 'wca-num'); td('Status category', el('span', { class: 'wci-badge is-' + r.category }, DATA.categories[r.category]));
            td('Appeal no.', r.appealNo ? (function () { var a = el('a', { class: 'wsi-link', href: LIVE.appealUrl + '/' + r.appealId + '?return=' + encodeURIComponent('/cgs/candidacy/insights') }, r.appealNo); return a; })() : '—', 'wca-nowrap');
            td('Reminders sent', r.reminders.length ? r.reminders.map(function (x) { return x.m + ' mo'; }).join(', ') : '—'); td('Last reminder', r.lastReminder ? fmtDate(r.lastReminder) : '—', 'wca-nowrap');
            tb.appendChild(tr);
        });
        table.appendChild(tb); wrap.appendChild(table); box.appendChild(wrap);
        pagination(box, tableState.page, pages, rows.length, 'students', function (n) { tableState.page = n; paintList(); });
    }
    document.getElementById('wci-search').addEventListener('input', function (e) { tableState.q = e.target.value; tableState.page = 1; paintList(); });
    charts.push({ id: 'list', paint: paintList });

    // ================================================================ Chart 3: Reminders sent (by milestone) and the response line
    function remMonths() { var m = DATA.today.slice(0, 7), out = []; for (var i = 6; i >= 0; i--) { out.push(ymAdd(m, -i)); } return out; }
    function remCtx() {
        return DATA.reminders.filter(function (r) { return (!filters.dept || r.dept === filters.dept) && (!filters.level || r.level === filters.level) && (!filters.mode || r.mode === filters.mode) && (!filters.category || r.category === filters.category); });
    }
    function remHl(r) {
        return (!highlight.category.length || (r.category && highlight.category.indexOf(r.category) !== -1)) && (!highlight.remMonth.length || highlight.remMonth.indexOf(r.month) !== -1) && (!highlight.milestone.length || highlight.milestone.indexOf(r.m) !== -1);
    }
    function paintReminders() {
        var months = remMonths(), ctx = remCtx().filter(function (r) { return months.indexOf(r.month) !== -1; });
        var active = highlight.category.length || highlight.remMonth.length || highlight.milestone.length, hl = active ? ctx.filter(remHl) : ctx;
        var mk = function (rows) { var s = { 3: 0, 2: 0, 1: 0 }; rows.forEach(function (r) { s[r.m]++; }); return s; };
        var groups = months.map(function (m) {
            var inC = ctx.filter(function (r) { return r.month === m; }), segs = mk(inC), solid = active ? mk(inC.filter(remHl)) : null, ans = inC.filter(function (r) { return r.responded; }).length;
            return { key: m, label: monthLabel(m), short: monthShort(m), sub: (m === months[0] || m.slice(5) === '01') ? m.slice(0, 4) : '', segs: segs, solid: solid, dim: 'remMonth', value: m,
                tip: [monthLabel(m), plural(inC.length, 'reminder', 'reminders') + ' sent', '3 months: ' + segs[3] + ' · 2 months: ' + segs[2] + ' · 1 month: ' + segs[1], ans + ' answered within 30 days'], ans: ans };
        });
        var box = document.getElementById('wci-reminders');
        stackedColumns(box, { label: 'Reminders sent per month by milestone', groups: groups, order: [3, 2, 1], colors: MILESTONE_COLOR, line: groups.map(function (g) { return g.ans; }), sharedAxis: true, lineColor: C.green, height: 260, emptyText: 'No reminders were sent in these months for these filters.' });
        var counts = mk(ctx);
        legend(box, [3, 2, 1].map(function (k) { return { label: k + '-month reminder', color: MILESTONE_COLOR[k], count: counts[k], dim: 'milestone', value: k }; }).concat([{ label: 'Answered within 30 days (line)', color: C.green, kind: 'line' }]));
        var answered = ctx.filter(function (r) { return r.responded; }).length, tile = document.getElementById('wci-response-tile'); clear(tile);
        tile.appendChild(el('div', { class: 'wsi-tile-value wsi-tabular' }, ctx.length ? Math.round(100 * answered / ctx.length) + '%' : '—'));
        tile.appendChild(el('div', { class: 'wsi-tile-label' }, 'Response rate · ' + answered + ' of ' + ctx.length));
    }
    charts.push({ id: 'reminders', paint: paintReminders });

    // ================================================================ Chart 4: Dismissal pipeline
    function paintDismissals() {
        var months = []; for (var i = 11; i >= 0; i--) { months.push(ymAdd(DATA.today.slice(0, 7), -i)); }
        var rows = DATA.dismissed.filter(demographic), sel = highlight.dismissedMonth, active = sel.length > 0;
        var groups = months.map(function (m) {
            var n = rows.filter(function (r) { return r.month === m; }).length;
            return { key: m, label: monthLabel(m), short: monthShort(m), sub: (m === months[0] || m.slice(5) === '01') ? m.slice(0, 4) : '', segs: { d: n }, solid: active ? { d: sel.indexOf(m) !== -1 ? n : 0 } : null, tip: [monthLabel(m), plural(n, 'student', 'students') + ' dismissed'], dim: 'dismissedMonth', value: m };
        });
        var box = document.getElementById('wci-dismissals');
        stackedColumns(box, { label: 'Students dismissed per month', groups: groups, order: ['d'], colors: { d: C.blue }, height: 240, emptyText: 'Nobody has been dismissed in these months for these filters.' });
        var cands = candidatesCtx(), tiles = document.getElementById('wci-dismissal-tiles'); clear(tiles);
        [['Candidates not yet exported', cands.filter(function (c) { return !c.exported; }).length], ['Exported, awaiting Registry', cands.filter(function (c) { return c.exported; }).length]].forEach(function (t) {
            var tile = el('a', { class: 'wsi-tile', href: LIVE.dismissalsUrl + (t[0].indexOf('not yet') !== -1 ? '?group=not_exported' : '?group=exported') });
            tile.appendChild(el('div', { class: 'wsi-tile-value wsi-tabular' }, String(t[1]))); tile.appendChild(el('div', { class: 'wsi-tile-label' }, t[0])); tiles.appendChild(tile);
        });
    }
    charts.push({ id: 'dismissals', paint: paintDismissals });


    // ---------------------------------------------------------------- live update
    var liveVersion = LIVE.version, livePaused = false, liveTimer = null;
    var liveText = document.getElementById('wsi-live-text'), liveDot = document.getElementById('wsi-live-dot'), liveNote = document.getElementById('wsi-live-note');
    function stamp() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2); }
    function setLive(text, cls) { liveText.textContent = text; liveDot.className = 'wsi-live-dot ' + (cls || ''); }
    function poll() {
        if (livePaused || document.hidden) { return; }
        fetch(LIVE.versionUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); }).then(function (j) {
            if (j.version === liveVersion) { setLive('Live · checked ' + stamp()); return; }
            return fetch(LIVE.dataUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j2) {
                liveVersion = j2.version; DATA = j2.data; // filters, highlight, search, page and scroll stay as they are
                renderAll(); setLive('Live · updated ' + stamp());
                liveNote.hidden = false; liveNote.textContent = 'The numbers changed and were updated in place.'; setTimeout(function () { liveNote.hidden = true; }, 6000);
            });
        }).catch(function () { setLive('Live · connection problem, retrying', 'wsi-live-error'); });
    }
    function schedule() { clearInterval(liveTimer); liveTimer = setInterval(poll, parseInt(LIVE.interval, 10) || 15000); }
    document.getElementById('wsi-live-pause').addEventListener('click', function (e) {
        livePaused = !livePaused; e.target.textContent = livePaused ? 'Resume updates' : 'Pause updates';
        setLive(livePaused ? 'Paused · updates are off' : 'Live · updated ' + stamp(), livePaused ? 'wsi-live-paused' : ''); if (!livePaused) { poll(); }
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
    window.addEventListener('resize', function () { clearTimeout(window.__wciR); window.__wciR = setTimeout(renderAll, 150); });

    renderFilterOptions();
    renderAll();
    schedule();
})();
