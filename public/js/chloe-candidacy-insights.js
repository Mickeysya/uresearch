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
        var padL = 34, padR = cfg.line ? 34 : 8, padT = 10, padB = 46;
        var plotW = width - padL - padR, plotH = height - padT - padB;
        var totals = cfg.groups.map(function (g) { return sum(cfg.order.map(function (k) { return g.segs[k] || 0; })); });
        var maxV = niceMax(Math.max.apply(null, [1].concat(totals)));
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
            var lab = svgEl('text', { x: x + bw / 2, y: height - padB + 16, 'text-anchor': 'middle', class: 'wsi-category-label' }); lab.textContent = g.short || g.label; svg.appendChild(lab);
            if (g.sub) { var sub = svgEl('text', { x: x + bw / 2, y: height - padB + 30, 'text-anchor': 'middle', class: 'wsi-axis-label' }); sub.textContent = g.sub; svg.appendChild(sub); }
            if (totals[i] > 0) { var tt = svgEl('text', { x: x + bw / 2, y: y(totals[i]) - 4, 'text-anchor': 'middle', class: 'wsi-value-label' }); tt.textContent = totals[i]; svg.appendChild(tt); }
            svg.appendChild(grp);
            wire(grp, g.dim, g.value, function () { return g.tip; }, g.onClick);
        });
        if (cfg.line) {
            var maxL = niceMax(Math.max.apply(null, [1].concat(cfg.line)));
            var yl = function (v) { return padT + plotH - (v / maxL) * plotH; };
            var pts = cfg.line.map(function (v, i) { return (padL + slot * i + slot / 2) + ',' + yl(v); }).join(' ');
            svg.appendChild(svgEl('polyline', { points: pts, fill: 'none', stroke: cfg.lineColor || C.slate, 'stroke-width': 2, 'pointer-events': 'none' }));
            cfg.line.forEach(function (v, i) { svg.appendChild(svgEl('circle', { cx: padL + slot * i + slot / 2, cy: yl(v), r: 3, fill: cfg.lineColor || C.slate, 'pointer-events': 'none' })); });
            [0, maxL / 2, maxL].forEach(function (v) { var tl2 = svgEl('text', { x: width - padR + 4, y: yl(v) + 4, class: 'wsi-axis-label' }); tl2.textContent = Math.round(v); svg.appendChild(tl2); });
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
