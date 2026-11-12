{{--
    Conflict-of-interest flags for one examiner on one candidate's panel
    (Support\ConflictOfInterest, CrossDepartmentClashes). Staff screens only.
    Expects: $flags.

    One pill per flag, stacked, in sentence case, and wrapping inside its
    cell (it used to be a single line that ran off the DAC table). A long
    flag shows its `summary` ("Co-author with supervisor (2024)"), with the
    full sentence in the tooltip and under "Show details".

    The <style> sits in the partial, once per page, so every screen that
    includes it (stage-1 card, Examiner Report, DAC Compilation, panel
    replacement) gets it without a shared stylesheet change. Tokens only,
    so light and dark mode both follow the theme.
--}}
@once
    <style>
        .coi-flags { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; margin-top: 3px; max-width: 320px; }
        .coi-flag { display: block; box-sizing: border-box; max-width: 100%; padding: 2px 8px; border-radius: 8px;
            font-size: var(--text-2xs); line-height: 1.4; text-transform: none; letter-spacing: normal; font-weight: 600;
            white-space: normal; overflow-wrap: anywhere; text-align: left; }
        .coi-flag.is-block { background: var(--danger-bg); color: var(--danger-fg); }
        .coi-flag.is-warning { background: var(--warning-bg); color: var(--warning-fg); }
        .coi-flag details { font-weight: 400; }
        .coi-flag summary { cursor: pointer; text-decoration: underline; }
        .coi-flag details p { margin: 2px 0 0; }
    </style>
@endonce
@if (count($flags))
    <div class="coi-flags">
        @foreach ($flags as $flag)
            @php($short = $flag['summary'] ?? null)
            <div class="coi-flag is-{{ $flag['level'] === 'block' ? 'block' : 'warning' }}" title="{{ $flag['message'] }}">
                {{ $flag['level'] === 'block' ? 'Conflict:' : 'Check:' }} {{ $short ?? $flag['message'] }}
                @if ($short)
                    <details><summary>Show details</summary><p>{{ $flag['message'] }}</p></details>
                @endif
            </div>
        @endforeach
    </div>
@endif
