@extends('core::layouts.app')

@section('title', 'Allowance Eligibility')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Allowance Eligibility: {{ $month->format('F Y') }}"
        subtitle="A reporting view, not a workflow: every row here is written by the daily Stage Gate evaluation, not entered by hand. Updates on the first of every month. Completing a Stage Gate only ever reveals what's owed; Admin or CGS still have to separately mark the actual payment complete, with proof, before the running total stops growing." />

    <div class="card card-wide">
        <p class="queue-meta" style="color: var(--navy); font-weight: 600;">
            Payable in {{ $month->format('F Y') }} is ordinarily for service completed in
            {{ $serviceMonth->format('F Y') }}: same as any payroll, this month's wage is last month's
            completed work. A student whose service only started this month shows nothing yet; it lands
            next month. The one exception: a termination entering, or an appeal just approved, surfaces
            immediately; each row's own "for {month}" label says which period it's actually showing.
        </p>

        <form method="GET" action="{{ route('allowance.index') }}" class="haziq-filter-bar">
            {{-- GRA and GA share this one screen and nav entry, each with
                 its own rules/table/export underneath -- confirmed
                 explicitly 2026-11-10: "ga allowoance eleigability masuk
                 je dekat page allowance eligability. cuma page tu leh
                 filter tuk ga or gra... apply for admin dah ke atas." Never
                 for a student -- confirmed the same day: a student reaches
                 this page through their own "My Allowance"/"My GA
                 Allowance" link, which already only appears once that
                 module's application is approved, so a toggle here would
                 let them poke at a module they may have nothing approved
                 in at all. $canExport is already exactly "not a student"
                 in both controllers. --}}
            @if ($canExport ?? false)
                <select name="module" data-autosubmit>
                    <option value="gra" @selected(($module ?? 'gra') === 'gra')>GRA</option>
                    <option value="ga" @selected(($module ?? 'gra') === 'ga')>GA</option>
                </select>
            @else
                {{-- No select to toggle, but the month filter below still
                     has to keep whichever module this student's own link
                     pointed at, or changing the month would silently drop
                     back to GRA. --}}
                <input type="hidden" name="module" value="{{ $module ?? 'gra' }}">
            @endif
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" data-autosubmit>
            <select name="status" data-autosubmit>
                <option value="">Any Status</option>
                @foreach (['eligible' => 'Eligible', 'withheld' => 'Withheld', 'payment_pending' => 'Payment Pending', 'review_required' => 'Review Required', 'terminated' => 'Terminated'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
        @include('haziq::partials.filter_autosubmit_scripts')

        @if ($canExport ?? false)
            <p style="margin-top: 10px;">
                <a href="{{ route('allowance.export', ['month' => $month->format('Y-m')]) }}" class="btn-secondary">
                    Download Excel ({{ $month->format('F Y') }}, active students only)
                </a>
            </p>
        @endif

        @if ($trend ?? false)
            @include('core::dashboard.partials.chartjs')
            <p class="queue-meta" style="margin-top: 14px;">Your allowance over the last 6 months, at a glance: each point is labelled by the month it's paid out in, one month after the service it covers.</p>
            <div style="height: 200px;">
                <canvas id="my-allowance-trend" role="img"
                        aria-label="My allowance trend: {{ $trend->map(fn ($t) => $t['month'].' '.($t['amount'] ?? 'blank'))->implode(', ') }}"></canvas>
            </div>

            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('my-allowance-trend');
                        if (! el || typeof Chart === 'undefined') return;

                        function token(name, fallback) { return function () { return Chart.uresearchToken(name, fallback); }; }

                        new Chart(el, {
                            type: 'line',
                            data: {
                                labels: @json($trend->pluck('month')),
                                datasets: [{
                                    label: 'Allowance (RM)',
                                    data: @json($trend->pluck('amount')),
                                    borderColor: token('--navy', '#284B80'),
                                    backgroundColor: token('--accent-subtle', '#E2EAFA'),
                                    fill: true,
                                    tension: 0.25,
                                    spanGaps: true,
                                    pointRadius: 4,
                                }],
                            },
                            options: {
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: false },
                                    datalabels: { display: false },
                                },
                                scales: {
                                    y: { beginAtZero: true, grid: { color: token('--border-grey', '#F1F3F7') }, border: { display: false } },
                                    x: { grid: { display: false }, border: { display: false } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush
        @endif

        @php($anomalyThreshold = 3)
        @if (($canExport ?? false) && $rows->isNotEmpty())
            @php($buckets = ['0' => 0, '1–2' => 0, "{$anomalyThreshold}+ (flagged)" => 0])
            @foreach ($rows as $row)
                {{-- Months actually accruing, not the raw outstanding streak —
                     an old debt frozen by a termination for years shouldn't
                     bucket the same as one still actively growing every
                     month. Confirmed explicitly 2026-11-03. --}}
                @php($m = $row['monthsAccruing'])
                @if ($m <= 0) @php($buckets['0']++)
                @elseif ($m < $anomalyThreshold) @php($buckets['1–2']++)
                @else @php($buckets["{$anomalyThreshold}+ (flagged)"]++)
                @endif
            @endforeach

            @include('core::dashboard.partials.chartjs')
            <p class="queue-meta" style="margin-top: 14px;">How many months of allowance each student is currently sitting on, grouped by months actually accruing, not real ML, just a threshold flag, but it surfaces who to look at first. A debt frozen by a termination doesn't count against a student here even if it's been outstanding a long time; see the table below for that total.</p>
            <div style="height: 180px;">
                <canvas id="allowance-anomaly" role="img"
                        aria-label="Students by months accruing: {{ collect($buckets)->map(fn ($c, $l) => "$l: $c")->implode(', ') }}"></canvas>
            </div>

            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('allowance-anomaly');
                        if (! el || typeof Chart === 'undefined') return;

                        function token(name, fallback) { return function () { return Chart.uresearchToken(name, fallback); }; }

                        new Chart(el, {
                            type: 'bar',
                            data: {
                                labels: @json(array_keys($buckets)),
                                datasets: [{
                                    label: 'Students',
                                    data: @json(array_values($buckets)),
                                    backgroundColor: [
                                        Chart.uresearchToken('--success-fg', '#07794A'),
                                        Chart.uresearchToken('--warning-fg', '#C28E40'),
                                        Chart.uresearchToken('--danger-fg', '#D0342C'),
                                    ],
                                    borderRadius: 5,
                                    borderSkipped: false,
                                    maxBarThickness: 64,
                                }],
                            },
                            options: {
                                indexAxis: 'y',
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: false },
                                    datalabels: { display: true, anchor: 'end', align: 'end', color: token('--text-body', '#23283A'), font: { size: 11, weight: '700' } },
                                },
                                scales: {
                                    x: { beginAtZero: true, grid: { color: token('--border-grey', '#F1F3F7') }, border: { display: false }, ticks: { precision: 0 } },
                                    y: { grid: { display: false }, border: { display: false } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush
        @endif

        <div style="overflow-x: auto;">
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Allowance</th>
                        <th>Months Withheld</th>
                        <th>Status</th>
                        @if ($canConfirmPayment ?? false)<th>Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row['student']->name }}
                                <br><span style="color: var(--text-grey); font-size: 12px;">
                                    {{ $row['student']->matric_no }} &middot; {{ $row['application']->reference() }}
                                </span>
                            </td>
                            <td>
                                {{ $row['amount'] === null ? '—' : 'RM '.number_format($row['amount'], 2) }}
                                @if ($row['amount'] !== null)
                                    <br><span style="color: var(--text-grey); font-size: 11px;">for {{ $row['periodMonth']->format('M Y') }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $row['monthsAccruing'] > 0 ? $row['monthsAccruing'] : '—' }}
                                @if ($row['monthsAccruing'] >= $anomalyThreshold)
                                    <span title="{{ $anomalyThreshold }}+ months accruing, flagged for attention" style="color: var(--danger-fg); font-weight: 700;">⚠</span>
                                @endif
                                @if ($row['monthsAccumulating'] > $row['monthsAccruing'])
                                    <br><span style="color: var(--text-grey); font-size: 11px;">
                                        {{ $row['monthsAccumulating'] }} outstanding total ({{ $row['monthsAccumulating'] - $row['monthsAccruing'] }} frozen by a termination)
                                    </span>
                                @endif
                            </td>
                            <td><x-core::status-badge :status="$row['displayStatus']" /></td>
                            @if ($canConfirmPayment ?? false)
                                <td>
                                    @if ($row['confirmable'])
                                        <form method="POST" action="{{ route('allowance.confirm-payment', $row['application']) }}" enctype="multipart/form-data" style="margin-bottom: 8px;">
                                            @csrf
                                            <input type="file" name="proof" required style="font-size: 12px; max-width: 160px;">
                                            <button type="submit" data-confirm="Confirm RM {{ number_format($row['amount'], 2) }} paid to {{ $row['student']->name }}? Every stakeholder and the student will be notified.">Mark Payment Complete</button>
                                        </form>
                                    @endif
                                    @if ($row['reviewable'])
                                        @if ($row['pendingForNextMonth'] !== null)
                                            {{-- CGS/Admin only — the student never sees this until it
                                                 actually shows up on next month's row. --}}
                                            <p class="queue-meta" style="margin: 0 0 4px; color: var(--navy);">
                                                Queued for next month: <b>RM {{ number_format($row['pendingForNextMonth'], 2) }}</b>, editable until then.
                                            </p>
                                        @endif
                                        <form method="POST" action="{{ route('allowance.set-review-amount', $row['application']) }}">
                                            @csrf
                                            <p class="queue-meta" style="margin: 0 0 4px;">
                                                {{ $row['amount'] === null ? 'Terminated or reinstated mid-month: decide how much is actually owed, if anything. Shows on next month\'s row.' : 'Reinstated mid-month: how much extra this partial month is worth. Shows on next month\'s row, not this one.' }}
                                            </p>
                                            <input type="number" step="0.01" min="0" name="amount" placeholder="RM"
                                                   value="{{ $row['pendingInput'] !== null ? number_format($row['pendingInput'], 2, '.', '') : '' }}"
                                                   required style="max-width: 100px;">
                                            <button type="submit" data-confirm="Set this month's allowance for {{ $row['student']->name }}?">{{ $row['pendingForNextMonth'] !== null ? 'Update Amount' : 'Set Amount' }}</button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ ($canConfirmPayment ?? false) ? 5 : 4 }}"><div class="empty-state">No allowance records for this month.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@include('haziq::partials.confirm_scripts')
@include('haziq::partials.assistant_widget')
@endsection
