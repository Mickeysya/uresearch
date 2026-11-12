{{--
    The five headline figures across the top of the CGS dashboard.

    Same $cards-array shape as the student dashboard's stat cards: add,
    remove or reorder an entry and the grid reflows on its own.

    'delta' is a month-over-month percentage from CgsDashboard::withTrend().
    It is null when last month had nothing to compare against, and the card
    then shows nothing rather than a misleading "0%".
--}}
@php
    $cards = [
        ['tone'=>'purple','panel'=>'applications','icon'=>'doc','label'=>'Total Applications',
         'figure'=>$totalApplications,'pill'=>'View All','pillTone'=>'info','route'=>'applications.index'],
        ['tone'=>'orange','panel'=>'applications','icon'=>'clock','label'=>'Pending My Action',
         'figure'=>$pendingMyAction,'pill'=>'View Pending','pillTone'=>'warn','route'=>null,
         // Of those, this officer's own modules (config/cgs_assignment.php).
         'note'=>isset($assignedToMe) ? number_format($assignedToMe).' assigned to you' : null],
        ['tone'=>'green','panel'=>'applications','icon'=>'check','label'=>'Approved',
         'figure'=>$approvedCount,'pill'=>'View Approved','pillTone'=>'good','route'=>null],
        ['tone'=>'red','panel'=>'applications','icon'=>'cross','label'=>'Rejected',
         'figure'=>$rejectedCount,'pill'=>'View Rejected','pillTone'=>'critical','route'=>null],
        ['tone'=>'blue','panel'=>'students','icon'=>'people','label'=>'Active Students',
         'figure'=>$activeStudents,'pill'=>'View Students','pillTone'=>'info','route'=>'students.index'],
        ['tone'=>'orange','panel'=>'postgraduate','icon'=>'clock','label'=>'Stage Gate Bottleneck',
         'figure'=>$stageGateBottleneck,'pill'=>'View Stage Gates','pillTone'=>'warn','route'=>'stage-gate.index'],
        ['tone'=>'red','panel'=>'postgraduate','icon'=>'trend','label'=>'Allowance Anomalies',
         'figure'=>$allowanceAnomalies,'pill'=>'View Allowance','pillTone'=>'critical','route'=>'allowance.index'],
    ];
@endphp

<div class="sdash-stats">
    @foreach ($cards as $card)
        <div class="sdash-stat tone-{{ $card['tone'] }}">
            @if (isset($unavailable[$card['panel']]))
                @include('core::dashboard.partials.skeleton', ['type' => 'stat'])
            @endif

            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $card['icon']])
                </span>
                <span class="sdash-stat-label">{{ $card['label'] }}</span>
            </div>

            <div class="sdash-stat-figure">
                <span class="sdash-stat-value" data-count-to="{{ $card['figure']['count'] }}" data-count-delay="{{ round($loop->index * 0.05, 2) }}">{{ number_format($card['figure']['count']) }}</span>
                @if ($card['figure']['delta'] !== null)
                    @php $up = $card['figure']['delta'] >= 0; @endphp
                    <span class="sdash-delta {{ $up ? 'is-up' : 'is-down' }}" title="Compared with last month">
                        {{ $up ? '↑' : '↓' }} {{ abs($card['figure']['delta']) }}%
                    </span>
                @endif
            </div>
            {{-- "no prior month yet" under all five figures was noise; the note
                 only appears beside a real month-on-month change. --}}
            @if ($card['figure']['delta'] !== null)
                <div class="sdash-stat-note">vs last month</div>
            @endif

            @if (! empty($card['note']))
                <div class="sdash-stat-note">{{ $card['note'] }}</div>
            @endif

            {{-- A button only where there is somewhere to go. Pending, Approved
                 and Rejected used to render a button-shaped <span> that did
                 nothing when clicked. --}}
            @if ($card['route'])
                <a href="{{ route($card['route']) }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
            @endif
        </div>
    @endforeach
</div>

@include('core::dashboard.partials.count-up')
