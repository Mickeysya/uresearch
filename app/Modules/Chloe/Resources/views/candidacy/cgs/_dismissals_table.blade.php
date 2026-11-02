@php
    use App\Modules\Chloe\Support\CandidacyDismissalList as L;
    $base = array_diff_key($query, ['group' => 1, 'page' => 1]);
    $link = fn (?string $group, ?int $page = null) => route('candidacy.cgs.dismissals', array_filter($base + ['group' => $group, 'page' => $page]));
@endphp
<section class="wca-card" aria-label="Dismissal candidates">
    <div class="wca-toolbar cgs-export-row">
        <p class="wca-muted wca-small wca-toolbar-note">{{ $filtered ? 'The export contains exactly the '.$total.' student(s) shown by the current filters.' : 'No filter is applied: the export contains all '.$total.' candidate(s).' }}</p>
        <form method="POST" action="{{ route('candidacy.cgs.dismissals.export') }}">
            @csrf
            @foreach (array_diff_key($query, ['page' => 1]) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <button type="submit" class="btn-secondary" @disabled($total === 0)>{{ $filtered ? 'Export filtered list to Excel' : 'Export all candidates to Excel' }}</button>
        </form>
    </div>

    @if ($rows->isNotEmpty())
        <div class="wca-table-wrap">
            <table class="wca-table is-stack is-compact">
                <thead><tr>
                    <th>Student</th><th>Programme</th><th>Department</th><th>Expiry date</th><th class="wca-num">Days since expiry</th><th>Last appeal outcome</th><th>Exported on</th><th>Action</th>
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
                        <td class="wca-nowrap" data-label="Action">@if ($url)<a class="wca-btn is-row" href="{{ $url }}">View student</a>@else<span class="wca-btn is-row" aria-disabled="true" role="link" title="This student has no appeal to open">View student</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @include('chloe::candidacy.wca._pagination', ['page' => $f['page'], 'pages' => $pages, 'total' => $total, 'perPage' => $perPage, 'noun' => 'candidates', 'pageUrl' => fn ($n) => $link($f['group'], $n > 1 ? $n : null)])
</section>
