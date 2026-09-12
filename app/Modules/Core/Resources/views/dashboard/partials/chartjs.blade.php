{{--
    Loads Chart.js once per page, however many panels ask for it.

    Chart.js is the team's documented charting choice (docs/scope/technical.md)
    and was already being loaded by the approver dashboard, so the donuts and
    bar charts use it rather than hand-rolled SVG. What it buys over the SVG
    we had: styled tooltips that appear instantly and position themselves,
    responsive redraw, and no geometry maths to maintain.

    The attendance gauge is deliberately NOT a Chart.js chart. A half-doughnut
    is possible via `circumference`/`rotation`, but its band threshold ticks
    and value marker would each need a custom plugin — more code than the SVG
    it already is.

    THE DEFAULTS BELOW RUN IMMEDIATELY, not on DOMContentLoaded. Every chart
    is built from a script pushed to the end of <body>, which executes BEFORE
    DOMContentLoaded fires — so defaults set in that listener land after the
    charts already exist and do nothing. That is what left legends switched on,
    inflating each canvas until it pushed the dashboard off one screen.
--}}
@once
    @push('head')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            (function () {
                if (typeof Chart === 'undefined') return;

                Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
                Chart.defaults.font.size = 11;
                Chart.defaults.color = '#8A94A6';

                // Every panel here draws its own legend in HTML, beside or
                // under the chart, so Chart.js never draws one.
                Chart.defaults.plugins.legend.display = false;

                Chart.defaults.maintainAspectRatio = false;
                Chart.defaults.responsive = true;

                var tip = Chart.defaults.plugins.tooltip;
                tip.backgroundColor = '#23283A';
                tip.titleColor = '#FFFFFF';
                tip.bodyColor = '#E4E8F2';
                tip.padding = 10;
                tip.cornerRadius = 8;
                tip.displayColors = true;
                tip.boxPadding = 4;
                tip.titleFont = { size: 12, weight: '600' };
                tip.bodyFont = { size: 11.5 };
            })();
        </script>
    @endpush
@endonce
