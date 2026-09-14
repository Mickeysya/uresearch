{{--
    The five headline figures on the administrator's dashboard.

    Two of the five come with a caveat, both stated in the card itself rather
    than left for someone to discover:

      Active Courses  — there is no course model anywhere in the portal, so
                        this counts distinct `users.programme` values, which
                        is the nearest real figure.
      Faculty Members — there is no staff model either; staff are `users`
                        carrying an approver role.
--}}
@php
    $pct = fn ($v) => $v === null ? null : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';

    $cards = [
        ['tone'=>'blue',   'panel'=>'users',       'icon'=>'people',   'label'=>'Total Students',
         'value'=>number_format($totalStudents['count']), 'delta'=>$totalStudents['delta'], 'note'=>'vs last month'],
        ['tone'=>'green',  'panel'=>'users',       'icon'=>'book',     'label'=>'Active Courses',
         'value'=>number_format($programmes['count']),    'delta'=>null,  'note'=>'distinct programmes · no course model'],
        ['tone'=>'purple', 'panel'=>'users',       'icon'=>'building', 'label'=>'Faculty Members',
         'value'=>number_format($staffMembers['count']),  'delta'=>$staffMembers['delta'], 'note'=>'staff accounts'],
        ['tone'=>'orange', 'panel'=>'attendance',  'icon'=>'trend',    'label'=>'Avg. Attendance',
         'value'=>$pct($averageAttendance) ?? '—',        'delta'=>null,  'note'=>$averageAttendance === null ? 'no data yet' : 'across all students'],
        ['tone'=>'red',    'panel'=>'applications','icon'=>'folder',   'label'=>'Active Applications',
         'value'=>number_format($activeApplications['count']), 'delta'=>$activeApplications['delta'], 'note'=>'vs last month'],
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
                <span class="sdash-stat-value">{{ $card['value'] }}</span>
                @if ($card['delta'] !== null)
                    @php $up = $card['delta'] >= 0; @endphp
                    <span class="sdash-delta {{ $up ? 'is-up' : 'is-down' }}">{{ $up ? '↑' : '↓' }} {{ abs($card['delta']) }}%</span>
                @endif
            </div>

            <div class="sdash-stat-note">{{ $card['note'] }}</div>
        </div>
    @endforeach
</div>
