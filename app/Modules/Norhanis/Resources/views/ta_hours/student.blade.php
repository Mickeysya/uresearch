@extends('core::layouts.app')

@section('title', 'Daily Log TA Hours')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Daily Log TA Hours" />

    <div class="card card-wide">
        @if (! $eligible)
            {{-- Only GA/GRA students do TA hours. --}}
            <div class="empty-state">
                <p><b>You are not a GA or GRA.</b></p>
                <p>Daily Log TA Hours is for students holding an active Graduate Assistant (GA) or
                   Graduate Research Assistant (GRA) appointment.</p>
            </div>
        @else
            <p class="message-warning">Please ensure to select the <b>CORRECT</b> Claim Month and Year before Submitting your Request.</p>

            <form method="GET" action="{{ route('ta-hours.index') }}" class="app-form">
                <label for="ta-year">Year</label>
                <select name="year" id="ta-year" required>
                    @foreach ($years as $y)
                        <option value="{{ $y }}" @selected((int) old('year', now()->year) === $y)>{{ $y }}</option>
                    @endforeach
                </select>

                <label for="ta-month">Month</label>
                <select name="month" id="ta-month" required>
                    <option value="">-- Select --</option>
                    @foreach (range(1, 12) as $m)
                        <option value="{{ $m }}" @selected((int) old('month') === $m)>{{ \Illuminate\Support\Carbon::create(null, $m, 1)->format('F') }}</option>
                    @endforeach
                </select>
                @error('month') <p class="field-error">{{ $message }}</p> @enderror

                <button type="submit">Next</button>
            </form>

        @endif

        @if ($ta['known'] && $ta['type'])
            <p class="queue-meta" style="margin-top: 20px;">
                Approved TA hours this semester (since {{ $ta['from']->format('j M Y') }}):
                <b>{{ rtrim(rtrim(number_format($ta['hoursCompleted'], 2, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format($hoursRequired, 2, '.', ''), '0'), '.') }} hrs</b>.
                <span style="color: var(--text-grey);">Only hours your course lecturer has approved count, including on the Travel form.</span>
            </p>
        @endif

        @if ($months->isNotEmpty())
            <h3 style="margin-top: 24px;">Your months</h3>
            <div style="overflow-x: auto;">
                <table class="ta-table">
                    <thead>
                        <tr><th>Month</th><th>Approved</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $m)
                            <tr>
                                <td>{{ $m['label'] }}</td>
                                <td>{{ rtrim(rtrim(number_format($m['approved'], 2, '.', ''), '0'), '.') }} hrs</td>
                                <td>
                                    @foreach (['draft' => 'not submitted', 'pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'] as $state => $word)
                                        @if ($m['counts']->get($state))
                                            <span class="status-badge {{ $state }}">{{ $m['counts']->get($state) }} {{ $word }}</span>
                                        @endif
                                    @endforeach
                                </td>
                                <td>
                                    @if ($eligible)
                                        <a href="{{ route('ta-hours.sheet', [$m['year'], $m['month']]) }}">Open</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($eligible)
            @include('core::dashboard.partials.chartjs')
            <p class="queue-meta" style="margin-top: 20px;">Approved hours over the last 6 months, against the {{ rtrim(rtrim(number_format($cap, 2, '.', ''), '0'), '.') }}-hour monthly cap.</p>
            <div style="height: 200px;">
                <canvas id="ta-hours-trend" role="img"
                        aria-label="Approved TA hours by month: {{ $monthlyTrend->map(fn ($m) => $m['month'].' '.$m['hours'].' hrs')->implode(', ') }}"></canvas>
            </div>

            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('ta-hours-trend');
                        if (! el || typeof Chart === 'undefined') return;

                        var cap = @json($cap);
                        var hours = @json($monthlyTrend->pluck('hours'));
                        var colors = hours.map(function (h) {
                            return h > cap ? Chart.uresearchToken('--danger-fg', '#D0342C')
                                : h >= cap - 3 ? Chart.uresearchToken('--warning-fg', '#C28E40')
                                : Chart.uresearchToken('--success-fg', '#07794A');
                        });

                        new Chart(el, {
                            type: 'bar',
                            data: {
                                labels: @json($monthlyTrend->pluck('month')),
                                datasets: [{ label: 'Hours', data: hours, backgroundColor: colors, borderRadius: 5, borderSkipped: false, maxBarThickness: 48 }],
                            },
                            options: {
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: false },
                                    datalabels: { display: true, anchor: 'end', align: 'top', offset: 2, color: function () { return Chart.uresearchToken('--text-body', '#23283A'); }, font: { size: 11, weight: '700' } },
                                },
                                scales: {
                                    y: { beginAtZero: true, suggestedMax: cap + 3, grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } }, border: { display: false } },
                                    x: { grid: { display: false }, border: { display: false } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush
        @endif
    </div>
</div>
@endsection

@push('head')
    @include('norhanis::ta_hours._styles')
@endpush
