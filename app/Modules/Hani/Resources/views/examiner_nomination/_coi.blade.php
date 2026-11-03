{{--
    Conflict-of-interest flags for one examiner on one candidate's panel
    (Support\ConflictOfInterest). Staff screens only. Expects: $flags.
    Styles are inline-token so the partial works on every screen it is
    included in without a shared stylesheet change.
--}}
@foreach ($flags as $flag)
    <span class="coi-flag" style="display: block; margin-top: 3px; padding: 2px 8px; border-radius: 8px; font-size: var(--text-2xs);
        background: var(--{{ $flag['level'] === 'block' ? 'danger' : 'warning' }}-bg); color: var(--{{ $flag['level'] === 'block' ? 'danger' : 'warning' }}-fg);">
        {{ $flag['level'] === 'block' ? 'Conflict:' : 'Check:' }} {{ $flag['message'] }}
    </span>
@endforeach
