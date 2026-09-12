{{--
    Loads Chart.js once per page, however many panels ask for it.

    Chart.js is the team's documented charting choice (docs/scope/technical.md)
    and was already being loaded by the approver dashboard, so the donuts and
    bar charts use it rather than hand-rolled SVG.

    Two additions on top of the library:

      chartjs-plugin-datalabels  draws the value on top of each bar. Off by
                                 default; a chart opts in through its own
                                 `datalabels` options.

      an external tooltip        Chart.js draws its tooltip INSIDE the canvas,
                                 so it clips at the canvas edge and sits on top
                                 of the chart. This replaces it with a plain
                                 <div> appended to <body>, which belongs to no
                                 panel and is therefore clipped by nothing — it
                                 can overhang the card, the grid row, anything.

    The attendance gauge is deliberately NOT a Chart.js chart. A half-doughnut
    is possible via `circumference`/`rotation`, but its band threshold ticks
    and value marker would each need a custom plugin — more code than the SVG
    it already is.

    THE DEFAULTS BELOW RUN IMMEDIATELY, not on DOMContentLoaded. Every chart is
    built from a script pushed to the end of <body>, which executes BEFORE
    DOMContentLoaded fires — defaults set in that listener land after the charts
    already exist and do nothing.
--}}
@once
    @push('head')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
        <script @cspNonce>
            (function () {
                if (typeof Chart === 'undefined') return;

                Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
                Chart.defaults.font.size = 11;
                Chart.defaults.color = '#8A94A6';
                Chart.defaults.maintainAspectRatio = false;
                Chart.defaults.responsive = true;

                // Every panel draws its own legend in HTML, beside or under the
                // chart, so Chart.js never draws one.
                Chart.defaults.plugins.legend.display = false;

                if (typeof ChartDataLabels !== 'undefined') {
                    Chart.register(ChartDataLabels);
                    // Opt-in: a doughnut with five slices does not want numbers
                    // stamped across it, a bar chart does.
                    Chart.defaults.plugins.datalabels.display = false;
                }

                /* ---- the tooltip that can go anywhere ------------------- */

                function tooltipEl() {
                    var el = document.getElementById('uresearch-chart-tooltip');
                    if (! el) {
                        el = document.createElement('div');
                        el.id = 'uresearch-chart-tooltip';
                        el.className = 'chart-tip';
                        // On <body>, so no panel's overflow can clip it.
                        document.body.appendChild(el);
                    }
                    return el;
                }

                function externalTooltip(context) {
                    var el = tooltipEl();
                    var tip = context.tooltip;

                    if (! tip || tip.opacity === 0) {
                        el.classList.remove('is-visible');
                        return;
                    }

                    var title = (tip.title || []).join(' ');
                    var rows = (tip.body || []).map(function (b, i) {
                        var colours = (tip.labelColors || [])[i] || {};
                        var swatch = colours.backgroundColor || '#5B8FD4';
                        return '<span class="chart-tip-row">'
                            + '<span class="chart-tip-swatch" style="background:' + swatch + '"></span>'
                            + '<span>' + b.lines.join(' ') + '</span>'
                            + '</span>';
                    }).join('');

                    el.innerHTML = (title ? '<span class="chart-tip-title">' + title + '</span>' : '') + rows;

                    // Position against the page, then nudge back inside the
                    // viewport if the chart sits near an edge.
                    var rect = context.chart.canvas.getBoundingClientRect();
                    el.classList.add('is-visible');

                    var w = el.offsetWidth, h = el.offsetHeight;
                    var caretX = tip.caretX, caretY = tip.caretY;
                    var type = (context.chart.config.type || '').toLowerCase();
                    var left, top;

                    if (type === 'doughnut' || type === 'pie' || type === 'polarArea') {
                        // On a ring the caret sits ON the slice, so a tooltip
                        // centred there lands over the hole and hides the total.
                        // Push it outward along the centre -> slice vector and
                        // hang it off whichever side the slice is on, so the
                        // middle of the chart is never covered.
                        var cx = rect.width / 2, cy = rect.height / 2;
                        var dx = caretX - cx, dy = caretY - cy;
                        var len = Math.sqrt(dx * dx + dy * dy) || 1;
                        var reach = Math.min(rect.width, rect.height) / 2 + 14;

                        var px = cx + (dx / len) * reach;
                        var py = cy + (dy / len) * reach;

                        left = rect.left + window.scrollX + px + (dx >= 0 ? 8 : -w - 8);
                        top = rect.top + window.scrollY + py - h / 2;
                    } else {
                        // On a bar the caret sits at the top of the column,
                        // which is exactly where chartjs-plugin-datalabels
                        // prints the value. Clear it when that plugin is on
                        // for this chart, so the tooltip never lands on the
                        // number it is repeating.
                        var dl = (context.chart.options.plugins || {}).datalabels;
                        var labelled = dl && dl.display === true;
                        var labelFont = labelled && dl.font ? (dl.font.size || 11) : 0;
                        var labelOffset = labelled ? (dl.offset || 0) : 0;
                        // font box + the plugin's own offset + a little air
                        var clearance = labelled ? Math.round(labelFont * 1.45) + labelOffset + 10 : 12;

                        left = rect.left + window.scrollX + caretX - w / 2;
                        top = rect.top + window.scrollY + caretY - h - clearance;
                    }

                    var minLeft = window.scrollX + 8;
                    var maxLeft = window.scrollX + document.documentElement.clientWidth - w - 8;
                    left = Math.max(minLeft, Math.min(left, maxLeft));

                    // Keep it on screen vertically too.
                    var minTop = window.scrollY + 8;
                    var maxTop = window.scrollY + document.documentElement.clientHeight - h - 8;
                    if (top < minTop) {
                        top = (type === 'doughnut' || type === 'pie')
                            ? minTop
                            : rect.top + window.scrollY + caretY + 14;
                    }
                    top = Math.min(top, Math.max(minTop, maxTop));

                    el.style.left = Math.round(left) + 'px';
                    el.style.top = Math.round(top) + 'px';
                }

                Chart.defaults.plugins.tooltip.enabled = false;
                Chart.defaults.plugins.tooltip.external = externalTooltip;
                Chart.defaults.plugins.tooltip.displayColors = true;
            })();
        </script>
    @endpush
@endonce
