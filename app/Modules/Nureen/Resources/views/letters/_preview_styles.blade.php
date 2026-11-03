{{-- Shared by the letter controls in supervision/_detail and
     ga_certification/_detail. --}}
@once
    <style>
        .nureen-letter-actions {
            display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3);
            margin: var(--space-3) 0; padding: var(--space-3);
            border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); background: var(--surface);
        }
        .nureen-letter-actions form { margin: 0; display: flex; gap: var(--space-3); align-items: center; }
        .nureen-letter-actions .hint { flex-basis: 100%; margin: 0; font-size: var(--text-xs); color: var(--text-grey); }
        /* Endorsing IS the approval at this stage, so Core's generic
           Approve button would only be a second, unlabelled way of doing
           the same thing. Its Reject (with the mandatory reason) stays.
           Scoped to rows carrying this panel; see SHARED_CHANGES.md §7. */
        .queue-row:has(.nureen-endorse) .decision-row button[value="approve"] { display: none; }
    </style>
@endonce
