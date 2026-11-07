{{-- Available, On Gap, Assigned or Unavailable, on every list of the pool. $examiner required. Styled by _pool_styles. --}}
<span class="examiner-state-badge tone-{{ $examiner->stateTone() }}">{{ $examiner->stateLabel() }}</span>
