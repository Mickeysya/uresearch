{{--
    The look of the document lists (pack/_inline_documents in the queue,
    pack/documents on its own page), pushed into the layout's <head> once
    however many queue cards include it.
--}}
@once
    @push('head')
        <style>
            .pv-box { background: var(--surface); border: 1px solid var(--border-grey); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: var(--space-2) var(--space-6); }
            .pv-box h3, .pv-inline h4 { margin: var(--space-4) 0 var(--space-2); }
            .pv-rows { list-style: none; margin: 0; padding: 0; }
            .pv-rows > li + li { border-top: 1px solid var(--border-grey); }
            .pv-row { display: flex; align-items: center; gap: var(--space-4); padding: var(--space-4) 0; }
            .pv-icon { flex: none; width: 34px; height: 40px; color: var(--navy); }
            .pv-title { flex: 1; min-width: 0; display: grid; gap: 2px; }
            .pv-title a { font-size: 16px; color: var(--navy); text-decoration: none; }
            .pv-title a:hover { text-decoration: underline; }
            .pv-title span { font-size: 12.5px; color: var(--text-grey); overflow-wrap: anywhere; }
            .pv-row .btn-secondary { flex: none; padding: var(--space-2) var(--space-4); font-size: 14px; }

            /* In the queue card. */
            .pv-inline { margin-top: var(--space-5); }
            .pv-inline .pv-box { padding: 0 var(--space-5); }

            /* Core's queue card lists the same files as plain links just
               below this module's section; this list replaces it. Only on
               cards where this list is drawn, so any other card keeps it. */
            .queue-row-body:has(.pv-inline) > .doc-list { display: none; }

            .pv-doc-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-4); padding: var(--space-4) 0; }
            .pv-doc-head .pv-title b { font-size: 18px; color: var(--text-dark); }
            .pv-frame { display: block; width: 100%; height: 78vh; border: 1px solid var(--border-grey); border-radius: var(--radius-md); background: var(--surface-sunken); margin-bottom: var(--space-5); }
            .pv-image { display: block; max-width: 100%; margin: 0 auto var(--space-5); border-radius: var(--radius-md); }
            .pv-none { padding: var(--space-6) 0; text-align: center; color: var(--text-grey); display: grid; gap: var(--space-3); justify-items: center; }

            @media (max-width: 640px) {
                .pv-box, .pv-inline .pv-box { padding: var(--space-2) var(--space-4); }
                .pv-row { flex-wrap: wrap; }
                .pv-row .btn-secondary { margin-left: calc(34px + var(--space-4)); }
                .pv-frame { height: 65vh; }
            }
        </style>
    @endpush
@endonce
