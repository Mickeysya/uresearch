/*
 * Charts for Process Analytics (Hani's module).
 *
 * Data comes from the page's <script type="application/json"> block; nothing
 * is fetched. Every colour is a FUNCTION reading a CSS token through
 * Chart.uresearchToken (core::dashboard.partials.chartjs), so Core's
 * retheme() repaints them in dark mode. A file, not an inline block:
 * script-src is 'self' plus the nonce.
 */
(function () {
    'use strict';

    function start() {
        var source = document.getElementById('process-analytics-data');
        if (!source || typeof Chart === 'undefined') return;

        var data = JSON.parse(source.textContent);
        var token = function (name, fallback) {
            return function () { return Chart.uresearchToken(name, fallback); };
        };
        var accent = token('--accent-solid', '#2F5597');
        var info = token('--info-fg', '#2456A6');
        var warning = token('--warning-fg', '#8A5D12');
        var danger = token('--danger-fg', '#B32D22');
        var grid = { color: token('--border-grey', '#F1F3F7') };
        var labels = {
            display: true, anchor: 'end', align: 'end', offset: 2,
            color: token('--text-body', '#23283A'), font: { size: 11, weight: '700' },
            formatter: function (v) { return v === null || v === 0 ? '' : v; }
        };
        var wrap = function (max) {
            return function (v) { var l = this.getLabelForValue(v); return l.length > max ? l.split(' ') : l; };
        };

        function draw(id, config) {
            var el = document.getElementById(id);
            if (el) new Chart(el, config);
        }

        // Days per stage, one chart per module. The slowest stage's average
        // bar takes the warning colour; the table beside it says the same.
        (data.stages || []).forEach(function (module) {
            draw('pa-stages-' + module.id, {
                type: 'bar',
                data: {
                    labels: module.labels,
                    datasets: [
                        {
                            label: 'Average days',
                            data: module.average,
                            backgroundColor: function (ctx) { return ctx.dataIndex === module.slowest ? warning() : accent(); },
                            borderRadius: 4, maxBarThickness: 28
                        },
                        { label: 'Median days', data: module.median, backgroundColor: info, borderRadius: 4, maxBarThickness: 28 }
                    ]
                },
                options: {
                    layout: { padding: { top: 18 } },
                    scales: {
                        x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, callback: wrap(12) } },
                        y: { beginAtZero: true, grid: grid, title: { display: true, text: 'Days' } }
                    },
                    plugins: { datalabels: labels }
                }
            });
        });

        draw('pa-pipeline', {
            type: 'bar',
            data: { labels: data.pipeline.labels, datasets: [{ label: 'Notices', data: data.pipeline.counts, backgroundColor: accent, borderRadius: 5, maxBarThickness: 30 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 28 } },
                scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } }, y: { grid: { display: false } } },
                plugins: { datalabels: labels }
            }
        });

        draw('pa-turnaround', {
            type: 'bar',
            data: {
                labels: data.turnaround.labels,
                datasets: [
                    { label: 'Average days', data: data.turnaround.average, backgroundColor: accent, borderRadius: 4, maxBarThickness: 40 },
                    { label: 'Median days', data: data.turnaround.median, backgroundColor: info, borderRadius: 4, maxBarThickness: 40 },
                    { label: 'Limit (days)', data: data.turnaround.limit, backgroundColor: danger, borderRadius: 4, maxBarThickness: 40 }
                ]
            },
            options: {
                layout: { padding: { top: 18 } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: grid, title: { display: true, text: 'Days' } } },
                plugins: { datalabels: labels }
            }
        });

        draw('pa-reviva-stages', {
            type: 'bar',
            data: { labels: data.revivaStages.labels, datasets: [{ label: 'Open rounds', data: data.revivaStages.counts, backgroundColor: accent, borderRadius: 5, maxBarThickness: 24 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 24 } },
                scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } }, y: { grid: { display: false } } },
                plugins: { datalabels: labels }
            }
        });

        draw('pa-reviva-outcomes', {
            type: 'bar',
            data: {
                labels: data.revivaOutcomes.labels,
                datasets: [
                    { label: 'Round 1', data: data.revivaOutcomes.first, backgroundColor: accent, borderRadius: 3, maxBarThickness: 34 },
                    { label: 'Round 2 or later', data: data.revivaOutcomes.later, backgroundColor: warning, borderRadius: 3, maxBarThickness: 34 }
                ]
            },
            options: {
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: grid, ticks: { precision: 0 } }
                }
            }
        });

        draw('pa-utilisation', {
            type: 'line',
            data: {
                labels: data.utilisation.labels,
                datasets: [{
                    label: 'Utilisation (%)',
                    data: data.utilisation.percent,
                    borderColor: accent,
                    backgroundColor: accent,
                    pointRadius: 3,
                    tension: 0.25
                }]
            },
            options: {
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, suggestedMax: 100, grid: grid, ticks: { callback: function (v) { return v + '%'; } } }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var i = ctx.dataIndex;
                                return ctx.parsed.y + '% (' + data.utilisation.busy[i] + ' of ' + data.utilisation.pool[i] + ' examiners)';
                            }
                        }
                    }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
