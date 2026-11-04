{{-- The examiners' categories disagree. Staff only. Expects: $divergence (?array{level, text}). --}}
@if ($divergence)
    <br><span class="status-badge {{ $divergence['level'] === 'major' ? 'rejected' : 'pending' }}"
              title="The two main examiners recommend different categories">
        {{ $divergence['level'] === 'major' ? 'Major divergence' : 'Reports differ' }}: {{ $divergence['text'] }}
    </span>
@endif
