{{-- The examiner's initials, without their title. $examiner required. Styled by _pool_styles. --}}
@php
    $initials = collect(explode(' ', preg_replace('/^(Dr\.|Prof\.|Prof\. Madya|Puan|En)\s+/', '', $examiner->name)))
        ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
@endphp
<span class="examiner-avatar">{{ strtoupper($initials) }}</span>
