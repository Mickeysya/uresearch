@php
    $cards = ['candidates' => ['Candidates', 'red', null], 'not_exported' => ['Not Yet Exported', 'gold', 'not_exported'], 'exported' => ['Exported', 'green', 'exported']];
    $base = array_diff_key($query, ['group' => 1, 'page' => 1]);
    $cardLink = fn (?string $group) => route('candidacy.cgs.dismissals', array_filter($base + ['group' => $group]));
@endphp
<div class="cgs-stats-row" role="group" aria-label="Dismissal candidates">
    @foreach ($cards as $key => [$label, $accent, $group])
        @php $active = $f['group'] === $group; @endphp
        <a href="{{ ($active || $group === null) ? $cardLink(null) : $cardLink($group) }}" class="{{ $active ? 'is-active' : '' }}" aria-pressed="{{ $active ? 'true' : 'false' }}">
            <div class="stat-card accent-{{ $accent }}"><div class="stat-number" data-live-stat="{{ $key }}">{{ $counts[$key] }}</div><div class="stat-label">{{ $label }}</div></div>
        </a>
    @endforeach
</div>
