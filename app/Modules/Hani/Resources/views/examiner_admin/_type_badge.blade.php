{{-- Internal or External, on every list of the pool. $examiner required. Styled by _pool_styles. --}}
<span class="examiner-state-badge tone-{{ $examiner->isExternal() ? 'info' : 'good' }}">{{ $examiner->typeLabel() }}</span>
