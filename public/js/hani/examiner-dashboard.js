/*
 * Charts for the examiner availability dashboard (Hani's module).
 *
 * Data comes from the page's <script type="application/json"> block; nothing
 * is fetched. Every colour is a FUNCTION reading a CSS token through
 * Chart.uresearchToken (core::dashboard.partials.chartjs), so Core's
 * retheme() repaints these in dark mode like every other chart. A file, not
 * an inline block: script-src is 'self' plus the nonce.
 */
(function () {
    'use strict';

    function start() {
        var source = document.getElementById('examiner-dashboard-data');
        if (!source || typeof Chart === 'undefined') return;

        var data = JSON.parse(source.textContent);
        var token = function (name, fallback) {
            return function () { return Chart.uresearchToken(name, fallback); };
        };
        var stateTokens = {
            available: token('--success-fg', '#07734A'),
            on_gap: token('--warning-fg', '#8A5D12'),
            assigned: token('--info-fg', '#2456A6'),
            unavailable: token('--danger-fg', '#B32D22')
        };
        var grid = { color: token('--border-grey', '#F1F3F7') };
        var labels = {
            display: true, anchor: 'end', align: 'end', offset: 2,
            color: token('--text-body', '#23283A'), font: { size: 11, weight: '700' },
            formatter: function (v) { return v || ''; }
        };

        function draw(id, config) {
            var el = document.getElementById(id);
            if (el) new Chart(el, config);
        }

        draw('exd-states', {
            type: 'doughnut',
            data: {
                labels: data.states.labels,
                datasets: [{
                    data: data.states.values,
                    backgroundColor: function (ctx) { return stateTokens[data.states.keys[ctx.dataIndex]](); },
                    borderColor: token('--surface', '#FFFFFF'),
                    borderWidth: 2
                }]
            },
            options: { cutout: '62%' }
        });

        draw('exd-stages', {
            type: 'bar',
            data: { labels: data.stages.labels, datasets: [{ data: data.stages.values, backgroundColor: token('--accent-solid', '#2F5597'), borderRadius: 5, maxBarThickness: 34 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 24 } },
                scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } }, y: { grid: { display: false } } },
                plugins: { datalabels: labels }
            }
        });

        draw('exd-departments', {
            type: 'bar',
            data: {
                labels: data.departments.labels,
                datasets: Object.keys(stateTokens).map(function (key, i) {
                    return {
                        label: data.states.labels[i],
                        data: data.departments.series[key] || [],
                        backgroundColor: stateTokens[key],
                        borderRadius: 3,
                        maxBarThickness: 38
                    };
                })
            },
            options: {
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 14 ? l.split(' ') : l; } } },
                    y: { stacked: true, beginAtZero: true, grid: grid, ticks: { precision: 0 } }
                }
            }
        });

        draw('exd-keywords', {
            type: 'bar',
            data: { labels: data.keywords.labels, datasets: [{ data: data.keywords.values, backgroundColor: token('--success-fg', '#07734A'), borderRadius: 5, maxBarThickness: 22 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 24 } },
                scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } }, y: { grid: { display: false } } },
                plugins: { datalabels: labels }
            }
        });

        draw('exd-gaps', {
            type: 'bar',
            data: {
                labels: data.gaps.labels,
                datasets: [
                    { label: 'Theses', data: data.gaps.theses, backgroundColor: token('--accent-solid', '#2F5597'), borderRadius: 4, maxBarThickness: 26 },
                    { label: 'Examiners covering it', data: data.gaps.examiners, backgroundColor: token('--success-fg', '#07734A'), borderRadius: 4, maxBarThickness: 26 }
                ]
            },
            options: {
                layout: { padding: { top: 18 } },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 12 ? l.split(' ') : l; } } },
                    y: { beginAtZero: true, grid: grid, ticks: { precision: 0 } }
                },
                plugins: { datalabels: labels }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
