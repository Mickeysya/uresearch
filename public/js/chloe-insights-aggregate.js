/*
 * Round 5 PART B.3/C. Pure aggregation/filter logic for the Workstation
 * Insights page -- NO DOM access anywhere in this file (no `document`,
 * no `window`, no element creation): every function here takes plain
 * data in and returns plain data out, so it can run in Node for a quick
 * parity check against app/Modules/Chloe/Support/InsightsAggregator.php
 * (the PHP reference implementation of these exact same rules) without a
 * browser at all. public/js/chloe-workstation-insights.js is the
 * drawing/DOM half; it calls into this module, never the other way
 * around.
 *
 * Exposed as `window.ChloeInsightsAggregate` in a browser, and as a
 * CommonJS export under Node (used by the parity check only).
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ChloeInsightsAggregate = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Holder-level dimensions never match a row whose own value for that
    // dimension is null/"none" -- a seat with no current holder (or no
    // resolvable programme/expiry/stay/release/key) is never "in" any
    // selected category of that dimension, filtered or not.
    var HOLDER_DIMENSIONS = ['programme', 'key', 'expiry', 'stay', 'release', 'source', 'due'];

    function isHolderDim(dim) {
        return HOLDER_DIMENSIONS.indexOf(dim) !== -1;
    }

    function rowValueForDim(row, dim) {
        switch (dim) {
            case 'release': return row.release_month != null ? row.release_month : null;
            case 'source': return row.release_source != null ? row.release_source : null;
            case 'due': return row.return_due != null ? row.return_due : null;
            // Not one of PART 3.1's 11 named dimensions -- added so the
            // "seats in a termination notice" KPI (Part 4.4 #3) can be
            // clicked to highlight across every chart the same way any
            // other category can; 'notice' is a plain boolean on every
            // row (never null/none, including available/maintenance
            // seats), so it is never a HOLDER_DIMENSIONS member.
            case 'notice': return row.notice ? 'true' : 'false';
            default: return row[dim] != null ? row[dim] : null;
        }
    }

    /**
     * True when row satisfies every dimension's filter in `filters`
     * EXCEPT `excludeDim` -- an empty array for a dimension means "no
     * filter on it", which always matches.
     * filters: { [dim]: string[] }
     */
    function rowMatches(row, filters, excludeDim) {
        for (var dim in filters) {
            if (!Object.prototype.hasOwnProperty.call(filters, dim)) {
                continue;
            }
            if (dim === excludeDim) {
                continue;
            }
            var selected = filters[dim] || [];
            if (selected.length === 0) {
                continue;
            }

            var rowValue = rowValueForDim(row, dim);

            if (isHolderDim(dim) && (rowValue === null || rowValue === 'none')) {
                return false;
            }

            if (selected.indexOf(rowValue) === -1) {
                return false;
            }
        }

        return true;
    }

    /** context[c] = count of rows with value c for `dim`, NO filters applied -- the fixed axis-scale source. */
    function context(rows, dim) {
        var counts = {};

        for (var i = 0; i < rows.length; i++) {
            var value = rowValueForDim(rows[i], dim);

            if (isHolderDim(dim) && (value === null || value === 'none')) {
                continue;
            }

            counts[value] = (counts[value] || 0) + 1;
        }

        return counts;
    }

    /** value[c] = count of rows with value c for `dim` that ALSO satisfy every OTHER dimension's filter. */
    function value(rows, dim, filters) {
        var counts = {};

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (!rowMatches(row, filters, dim)) {
                continue;
            }

            var v = rowValueForDim(row, dim);

            if (isHolderDim(dim) && (v === null || v === 'none')) {
                continue;
            }

            counts[v] = (counts[v] || 0) + 1;
        }

        return counts;
    }

    /** Every row satisfying every dimension's filter (no exclusion). */
    function filteredRows(rows, filters) {
        var result = [];
        for (var i = 0; i < rows.length; i++) {
            if (rowMatches(rows[i], filters)) {
                result.push(rows[i]);
            }
        }
        return result;
    }

    /**
     * Round 6 PART 3.2: highlighted[c] = count of `rows` (already the
     * current FILTER population) whose value for `dim` is c AND which
     * satisfy the FULL highlight selection (every highlight dimension,
     * `dim` itself included -- unlike value(), nothing is excluded). An
     * empty `highlight` matches every row, so highlighted[c] === the
     * plain per-category count of `rows`, which is exactly "looks fully
     * solid" / "drawn in full colour with the total label".
     */
    function highlightCounts(rows, dim, highlight) {
        var counts = {};

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (!rowMatches(row, highlight)) {
                continue;
            }

            var v = rowValueForDim(row, dim);

            if (isHolderDim(dim) && (v === null || v === 'none')) {
                continue;
            }

            counts[v] = (counts[v] || 0) + 1;
        }

        return counts;
    }

    /** True when any of the five HOLDER_DIMENSIONS has a non-empty selection in `dims`. */
    function hasHolderDimension(dims) {
        for (var i = 0; i < HOLDER_DIMENSIONS.length; i++) {
            if (dims[HOLDER_DIMENSIONS[i]] && dims[HOLDER_DIMENSIONS[i]].length > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * "Showing X of Y seat holders" (a holder-level dimension is part of
     * the FILTER or the HIGHLIGHT) or "Showing X of Y seats" -- X is the
     * count of `populationRows` (already filter-matched) that ALSO
     * match `highlight` (every row, when `highlight` is empty); Y is
     * populationRows.length itself.
     */
    function summaryCounts(populationRows, filters, highlight) {
        var hasHolderDim = hasHolderDimension(filters) || hasHolderDimension(highlight);

        var matching = filteredRows(populationRows, highlight).length;
        var total = populationRows.length;

        return { matching: matching, total: total, unit: hasHolderDim ? 'seat holders' : 'seats' };
    }

    function ymAdd(ym, n) {
        var parts = ym.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1 + n;
        y += Math.floor(m / 12);
        m = ((m % 12) + 12) % 12;
        return y + '-' + (m + 1 < 10 ? '0' : '') + (m + 1);
    }

    function ymDiffMonths(a, b) {
        var pa = a.split('-'), pb = b.split('-');
        return (parseInt(pb[0], 10) - parseInt(pa[0], 10)) * 12 + (parseInt(pb[1], 10) - parseInt(pa[1], 10));
    }

    /**
     * Round 6 PART 3.7/5.1: every month from `nowMonth` ("YYYY-MM") to
     * the last month with an actual release, capped at `maxMonths`;
     * releases further out than that are summed into one trailing
     * "later" bucket instead of their own month. `filters` is the
     * dropdown-filtered POPULATION's filter set (every holder-level
     * dimension included); `roomScopeFilters` is block/room/gender ONLY,
     * matching PART 5.1's own rule that "available now" is scoped by
     * room filters alone, never by programme/key.
     */
    function forecastMonths(rows, filters, roomScopeFilters, nowMonth, maxMonths) {
        maxMonths = maxMonths || 36;
        var filteredForReleases = filteredRows(rows, filters);

        var available = rows.filter(function (row) {
            return row.status === 'available' && rowMatches(row, roomScopeFilters);
        }).length;

        var lastIndex = 0;
        for (var i = 0; i < filteredForReleases.length; i++) {
            var rm = filteredForReleases[i].release_month;
            if (rm && rm !== 'none') {
                lastIndex = Math.max(lastIndex, ymDiffMonths(nowMonth, rm));
            }
        }
        var spanMonths = Math.min(lastIndex, maxMonths);

        var monthKeys = [];
        for (var j = 0; j <= spanMonths; j++) {
            monthKeys.push(ymAdd(nowMonth, j));
        }

        var cumulative = 0;
        var months = [];

        for (var k = 0; k < monthKeys.length; k++) {
            var month = monthKeys[k];
            var fromCandidacy = filteredForReleases.filter(function (r) { return r.release_month === month && r.release_source === 'candidacy'; }).length;
            var fromTermination = filteredForReleases.filter(function (r) { return r.release_month === month && r.release_source === 'termination'; }).length;
            var releases = fromCandidacy + fromTermination;
            cumulative += releases;

            months.push({
                month: month,
                from_candidacy: fromCandidacy,
                from_termination: fromTermination,
                releases: releases,
                projected_available: available + cumulative,
            });
        }

        var laterRows = filteredForReleases.filter(function (r) {
            return r.release_month !== 'none' && monthKeys.indexOf(r.release_month) === -1;
        });

        var later = null;
        if (laterRows.length > 0) {
            var fromCandLater = laterRows.filter(function (r) { return r.release_source === 'candidacy'; }).length;
            cumulative += laterRows.length;
            later = {
                from_candidacy: fromCandLater,
                from_termination: laterRows.length - fromCandLater,
                releases: laterRows.length,
                projected_available: available + cumulative,
            };
        }

        return { months: months, later: later };
    }

    /** Month span (inclusive) <= 24 -> 'month', else 'quarter' -- PART 3.7's own default-grain rule. */
    function defaultGrain(monthCount) {
        return monthCount <= 24 ? 'month' : 'quarter';
    }

    /**
     * Groups forecastMonths()'s own 'months' list into quarters or years
     * (or returns it unchanged for 'month'); each group's
     * projected_available is the LAST month in that group's own value
     * (a cumulative figure, so "at period end" is exactly the right one
     * to carry up), everything else summed across the group. Each group
     * also carries the literal 'months' (their Y-m keys) it was built
     * from, so a drill-down can re-slice the monthly series exactly.
     */
    function groupPeriods(months, grain) {
        if (grain === 'month') {
            return months.map(function (m) {
                return {
                    key: m.month, label: m.month,
                    from_candidacy: m.from_candidacy, from_termination: m.from_termination,
                    releases: m.releases, projected_available: m.projected_available,
                    months: [m.month],
                };
            });
        }

        var groups = {};
        var order = [];

        for (var i = 0; i < months.length; i++) {
            var m = months[i];
            var parts = m.month.split('-');
            var year = parseInt(parts[0], 10);
            var monthNum = parseInt(parts[1], 10);
            var key = grain === 'year' ? String(year) : (year + '-Q' + Math.ceil(monthNum / 3));

            if (!groups[key]) {
                groups[key] = { key: key, label: key, from_candidacy: 0, from_termination: 0, releases: 0, projected_available: 0, months: [] };
                order.push(key);
            }

            groups[key].from_candidacy += m.from_candidacy;
            groups[key].from_termination += m.from_termination;
            groups[key].releases += m.releases;
            groups[key].projected_available = m.projected_available;
            groups[key].months.push(m.month);
        }

        return order.map(function (key) { return groups[key]; });
    }

    /**
     * A click on a category: plain click sets the dimension's selection
     * to exactly [value] (replacing whatever was selected), UNLESS
     * value was already the ONLY thing selected, in which case it
     * clears the dimension entirely ("a click on the only selected
     * category clears it"). Ctrl/Cmd/Shift ("additive" true) instead
     * toggles value's OWN membership in the existing set, leaving the
     * rest alone. Returns a NEW filters object; never mutates the one
     * passed in.
     */
    function toggleFilter(filters, dim, value, additive) {
        var next = {};
        for (var k in filters) {
            if (Object.prototype.hasOwnProperty.call(filters, k)) {
                next[k] = filters[k].slice();
            }
        }

        var current = next[dim] || [];

        if (additive) {
            var idx = current.indexOf(value);
            if (idx === -1) {
                current = current.concat([value]);
            } else {
                current = current.slice(0, idx).concat(current.slice(idx + 1));
            }
        } else if (current.length === 1 && current[0] === value) {
            current = [];
        } else {
            current = [value];
        }

        next[dim] = current;

        return next;
    }

    /**
     * An empty dimension-state object -- every known dimension (all 11
     * from PART 3.1) present with an empty selection, so callers never
     * have to guard a missing key. Used for BOTH layers: the filter
     * state (only block/room/gender/programme/key ever get written to
     * by a dropdown) and the highlight state (any of the 11, written by
     * a chart click).
     */
    function emptyFilters() {
        return {
            block: [], room: [], gender: [], status: [], programme: [], key: [],
            expiry: [], stay: [], release: [], source: [], due: [], notice: [],
        };
    }

    /**
     * Parses a query string (e.g. "gender=female&block=N2,J2") into a
     * filters object -- comma-separated values per dimension, any
     * dimension missing from the string stays an empty selection. Pure
     * string in, object out -- the caller supplies the string (the
     * page's own current URL query), this never reads it itself.
     */
    function parseFiltersFromQueryString(queryString) {
        var filters = emptyFilters();
        var qs = (queryString || '').replace(/^[?]/, '');
        if (qs === '') {
            return filters;
        }

        var pairs = qs.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            var key = decodeURIComponent(pair[0] || '');
            var raw = decodeURIComponent((pair[1] || '').replace(/\+/g, ' '));

            if (Object.prototype.hasOwnProperty.call(filters, key) && raw !== '') {
                filters[key] = raw.split(',').filter(function (v) { return v !== ''; });
            }
        }

        return filters;
    }

    /** The inverse of parseFiltersFromQueryString() -- a query string with no leading "?", empty dimensions omitted entirely. */
    function filtersToQueryString(filters) {
        var parts = [];
        for (var dim in filters) {
            if (!Object.prototype.hasOwnProperty.call(filters, dim)) {
                continue;
            }
            var selected = filters[dim];
            if (selected && selected.length > 0) {
                parts.push(encodeURIComponent(dim) + '=' + selected.map(encodeURIComponent).join(','));
            }
        }
        return parts.join('&');
    }

    return {
        HOLDER_DIMENSIONS: HOLDER_DIMENSIONS,
        rowValueForDim: rowValueForDim,
        rowMatches: rowMatches,
        context: context,
        value: value,
        highlightCounts: highlightCounts,
        hasHolderDimension: hasHolderDimension,
        filteredRows: filteredRows,
        summaryCounts: summaryCounts,
        forecastMonths: forecastMonths,
        defaultGrain: defaultGrain,
        groupPeriods: groupPeriods,
        toggleFilter: toggleFilter,
        emptyFilters: emptyFilters,
        parseFiltersFromQueryString: parseFiltersFromQueryString,
        filtersToQueryString: filtersToQueryString,
    };
}));
