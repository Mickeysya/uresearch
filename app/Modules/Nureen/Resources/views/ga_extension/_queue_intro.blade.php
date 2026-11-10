{{-- Rendered through core::partials.queue's own $intro, not inlined above
     the include -- PageShellTest::test_no_queue_view_prints_anything_above_
     the_page_header() refuses a queue view that renders its own markup
     before the page title; $intro is the partial's own way to let a module
     add exactly this without landing there. See queue.blade.php. --}}

{{-- The reciprocal of ga_application/index.blade.php's own tab -- every
     role that reaches this queue (Admin, Supervisor, CGS team) also has
     access to GA Applicants, so this is unconditional here, unlike that
     page's own Research Centre exception. Inline flex, not
     .haziq-filter-bar -- that class only loads via Haziq's own
     partials.styles, never included on this, Nureen's, page. --}}
<div style="display: flex; gap: 10px; margin-bottom: 8px;">
    <a href="{{ route('ga-application.index') }}" class="btn-secondary">Application</a>
    <a href="{{ route('ga-extension.queue') }}" class="btn" style="pointer-events: none; background: var(--accent-solid); color: var(--text-on-accent);">Extension</a>
</div>

{{-- Always shown, zero bars and all -- matching GRA/GA Applicants' own
     "Pending by Stage" cards, which never hide behind an empty check
     either. Confirmed explicitly 2026-11-10: "kenapa ga extention tak
     keluar sama pun cam gra extention layout" -- hiding this card whenever
     every stage was at zero was the one thing that made this page look
     different from theirs when nothing is pending, not the plain queue
     list below it. --}}
<div class="card card-wide">
    <h3 style="margin-top: 0;">Pending by Stage</h3>
    <p class="queue-meta">Where GA Extension requests are piling up right now.</p>

    @include('core::dashboard.partials.chartjs')
    <div style="height: 200px;">
        <canvas id="ga-extension-bottleneck" role="img"
                aria-label="Pending GA Extension requests by stage: {{ $bottleneck->map(fn ($b) => $b['label'].' '.$b['count'])->implode(', ') }}"></canvas>
    </div>

    @push('scripts')
        <script @cspNonce>
            (function () {
                var el = document.getElementById('ga-extension-bottleneck');
                if (! el || typeof Chart === 'undefined') return;

                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: @json($bottleneck->pluck('label')),
                        datasets: [{
                            label: 'Pending',
                            data: @json($bottleneck->pluck('count')),
                            backgroundColor: function () { return Chart.uresearchToken('--navy', '#284B80'); },
                            borderRadius: 5,
                            borderSkipped: false,
                            maxBarThickness: 64,
                        }],
                    },
                    options: {
                        layout: { padding: { top: 18 } },
                        animation: { duration: 650, easing: 'easeOutQuart' },
                        scales: {
                            x: { grid: { display: false }, border: { display: false } },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } },
                                ticks: { precision: 0 },
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            datalabels: {
                                display: true,
                                anchor: 'end',
                                align: 'top',
                                offset: 2,
                                color: function () { return Chart.uresearchToken('--text-body', '#23283A'); },
                                font: { size: 11, weight: '700' },
                            },
                        },
                    },
                });
            })();
        </script>
    @endpush
</div>
