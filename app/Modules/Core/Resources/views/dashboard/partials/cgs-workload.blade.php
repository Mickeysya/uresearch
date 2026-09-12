{{--
    "Workload Overview" — what is on this role's desk, split by module.

    The donut is plain SVG, like the student dashboard's gauge: one ring of
    segments, sized from the same numbers the legend prints, so the two can
    never disagree. Rows come from CgsDashboard::workload(), which asks
    ModuleRegistry which stages this role owns — so a teammate's new module
    appears here on its own.
--}}
@php
    $total = (int) $workload->sum('count');
    // r=54 ring in a 140x140 box; circumference drives every segment length.
    $r = 54; $circ = 2 * M_PI * $r;
    $palette = ['#5B8FD4', '#4FA86B', '#E9B23C', '#D0342C', '#6B4FD8', '#17A8B8'];
    $offset = 0.0;
    $gap = 3.0;   // hairline separation between segments, in path units
@endphp

<section class="sdash-card">
    @if (isset($unavailable['applications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Workload Overview</h3>
    </header>

    <div class="sdash-card-body cgs-workload-body">
        <div class="cgs-donut-wrap">
            <svg class="cgs-donut" viewBox="0 0 140 140" role="img"
                 style="--circ: {{ round($circ, 2) }}"
                 aria-label="{{ $total }} applications pending, split by module">
                <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="#EDEFF4" stroke-width="18"/>

                {{-- One rotation for the whole ring (so it starts at twelve
                     o'clock instead of three), then each segment is placed by
                     stroke-dashoffset. Rotating each circle individually is
                     what broke this: the attribute's own origin and a CSS
                     transform-origin were applied twice over. --}}
                <g transform="rotate(-90 70 70)">
                    @foreach ($workload as $i => $row)
                        @php
                            $len = $total > 0 ? ($row['count'] / $total) * $circ : 0;
                            // A hairline gap between neighbours, as in the design.
                            // Never eat a whole segment: a 1-of-40 slice stays visible.
                            $drawn = max($len - $gap, min($len, 2.0));
                        @endphp
                        <circle class="cgs-donut-seg" cx="70" cy="70" r="{{ $r }}" fill="none"
                                stroke="{{ $palette[$i % count($palette)] }}" stroke-width="18"
                                stroke-dasharray="{{ round($drawn, 2) }} {{ round($circ - $drawn, 2) }}"
                                stroke-dashoffset="{{ round(-$offset, 2) }}"
                                style="--seg-delay: {{ round($i * 0.11, 2) }}s"/>
                        @php $offset += $len; @endphp
                    @endforeach
                </g>
            </svg>
            <div class="cgs-donut-centre">
                <span class="cgs-donut-label">Total</span>
                <span class="cgs-donut-number" data-count-to="{{ $total }}" data-count-duration="{{ 650 + $workload->count() * 110 }}">{{ $total }}</span>
                <span class="cgs-donut-label">Pending</span>
            </div>
        </div>

        <ul class="cgs-workload-legend">
            @forelse ($workload as $i => $row)
                <li style="--seg-delay: {{ round($i * 0.11, 2) }}s">
                    <span class="sdash-dot" style="background: {{ $palette[$i % count($palette)] }}" aria-hidden="true"></span>
                    <span class="cgs-legend-label">{{ $row['label'] }}</span>
                    <span class="cgs-legend-count">{{ $row['count'] }} <span>({{ $row['share'] }}%)</span></span>
                </li>
            @empty
                <li class="cgs-legend-empty">Nothing is waiting on you.</li>
            @endforelse
        </ul>
    </div>

    <footer class="sdash-note {{ $total > 0 ? '' : 'tone-good' }}">
        <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
        <span>
            @if ($total > 0)
                You have {{ $total }} {{ Str::plural('application', $total) }} pending your action.
            @else
                Your queues are clear.
            @endif
        </span>
    </footer>
</section>

@include('core::dashboard.partials.count-up')
