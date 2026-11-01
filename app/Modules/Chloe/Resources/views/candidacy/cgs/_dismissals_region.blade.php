@php
    use App\Modules\Chloe\Support\CandidacyDismissalList as L;
    $cards = ['candidates' => ['Candidates', 'red', null], 'not_exported' => ['Not yet exported', 'gold', 'not_exported'], 'exported' => ['Exported (awaiting Registry)', 'green', 'exported']];
    $base = array_diff_key($query, ['group' => 1, 'page' => 1]);
    $link = fn (?string $group, ?int $page = null) => route('candidacy.cgs.dismissals', array_filter($base + ['group' => $group, 'page' => $page]));
@endphp
<div class="wca-cards" role="group" aria-label="Dismissal candidates">
    @foreach ($cards as $key => [$label, $tone, $group])
        @php $active = $f['group'] === $group; @endphp
        <a class="wca-statcard {{ $active ? 'is-active' : '' }}" href="{{ ($active || $group === null) ? $link(null) : $link($group) }}" aria-pressed="{{ $active ? 'true' : 'false' }}">
            <span class="wca-statcard-label"><span class="wca-dot is-{{ $tone }}"></span>{{ $label }}</span>
            <span class="wca-statcard-number" data-live-stat="{{ $key }}">{{ $counts[$key] }}</span>
        </a>
    @endforeach
</div>

<section class="wca-card" aria-label="Dismissal candidates">
    <div class="wca-toolbar">
        <p class="wca-muted wca-small wca-toolbar-note">{{ $filtered ? 'The export contains exactly the '.$total.' student(s) shown by the current filters.' : 'No filter is applied: the export contains all '.$total.' candidate(s).' }}</p>
        <form method="POST" action="{{ route('candidacy.cgs.dismissals.export') }}">
            @csrf
            @foreach (array_diff_key($query, ['page' => 1]) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <button type="submit" class="wca-btn is-primary" @disabled($total === 0)>{{ $filtered ? 'Export filtered list to Excel' : 'Export all candidates to Excel' }}</button>
        </form>
    </div>

    @if ($rows->isNotEmpty())
        <div class="wca-table-wrap">
            <table class="wca-table is-stack is-compact">
                <thead><tr>
                    <th>Student</th><th>Programme</th><th>Department</th><th>Expiry date</th><th class="wca-num">Days since expiry</th><th>Last appeal outcome</th><th>Exported on</th><th></th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    @php $url = L::detailUrl($r, $returnTo); @endphp
                    <tr>
                        <td data-label="Student">{{ $r['name'] }}<br><span class="wca-muted wca-small">{{ $r['studentId'] }}</span></td>
                        <td data-label="Programme">{{ $r['programme'] ?? '—' }}</td>
                        <td data-label="Department">{{ $r['department']?->name ?? '—' }}</td>
                        <td class="wca-nowrap" data-label="Expiry date">{{ $r['expiry']->format('j M Y') }}</td>
                        <td class="wca-num" data-label="Days since expiry">{{ $r['daysSince'] }}</td>
                        <td data-label="Last appeal outcome">{{ L::OUTCOMES[$r['outcome']] }}</td>
                        <td class="wca-nowrap" data-label="Exported on">{{ $r['exportedAt'] ? $r['exportedAt']->format('j M Y, H:i') : '—' }}</td>
                        <td class="wca-nowrap" data-label="Open">@if ($url)<a class="wca-btn is-small" href="{{ $url }}">View student</a>@else<span class="wca-muted wca-small">No appeal</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @include('chloe::candidacy.wca._pagination', ['page' => $f['page'], 'pages' => $pages, 'total' => $total, 'perPage' => $perPage, 'noun' => 'candidates', 'pageUrl' => fn ($n) => $link($f['group'], $n > 1 ? $n : null)])
</section>
