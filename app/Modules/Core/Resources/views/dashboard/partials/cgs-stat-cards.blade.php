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
         'figure'=>$pendingMyAction,'pill'=>'View Pending','pillTone'=>'warn','route'=>null],
        ['tone'=>'green','panel'=>'applications','icon'=>'check','label'=>'Approved',
         'figure'=>$approvedCount,'pill'=>'View Approved','pillTone'=>'good','route'=>null],
        ['tone'=>'red','panel'=>'applications','icon'=>'cross','label'=>'Rejected',
         'figure'=>$rejectedCount,'pill'=>'View Rejected','pillTone'=>'critical','route'=>null],
        ['tone'=>'blue','panel'=>'students','icon'=>'people','label'=>'Active Students',
         'figure'=>$activeStudents,'pill'=>'View Students','pillTone'=>'info','route'=>'cgs.students.index'],
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
            <div class="sdash-stat-note">{{ $card['figure']['delta'] !== null ? 'vs last month' : 'no prior month yet' }}</div>

            @if ($card['route'])
                <a href="{{ route($card['route']) }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
            @else
                <span class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</span>
            @endif
        </div>
    @endforeach
</div>

@include('core::dashboard.partials.count-up')
