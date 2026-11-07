{{--
    Extra status-badge tones for Stage Gate / Allowance states that
    layout.css doesn't define (pending/approved/rejected/draft only).

    Reuses the exact same tokens and hex values layout.css already uses for
    those four, rather than introducing new colours, so a new tone reads as
    "the same palette, one more shade" instead of a different module's look.
    Loaded via @stack('head') from this module's own views only — nothing
    outside app/Modules/Haziq is edited to register it. No nonce needed:
    style-src already allows 'unsafe-inline' (see ContentSecurityPolicy).
--}}
<style>
    .status-badge.upcoming        { background: var(--light-blue-bg); color: var(--navy); }
    .status-badge.due_soon        { background: var(--light-gold-bg); color: #8A5F14; }
    .status-badge.grace_period    { background: var(--light-gold-bg); color: var(--gold); }
    .status-badge.grace_expired,
    .status-badge.withheld,
    .status-badge.forfeited,
    .status-badge.not_completed,
    .status-badge.terminated      { background: #FDEEED; color: #D0342C; }
    .status-badge.completed,
    .status-badge.backdated,
    .status-badge.eligible,
    .status-badge.active          { background: #E4F8ED; color: #07794A; }
    .status-badge.not_recorded    { background: var(--border-grey); color: var(--text-grey); }
    .status-badge.payment_pending { background: var(--light-gold-bg); color: #8A5F14; }
    .status-badge.review_required { background: var(--light-purple-bg); color: var(--navy); }
    .status-badge.master          { background: var(--light-blue-bg); color: var(--navy); }
    .status-badge.phd             { background: var(--light-purple-bg); color: var(--navy); }

    .haziq-filter-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
    }
    .haziq-filter-bar select {
        padding: 6px 10px;
        border: 1px solid var(--border-grey);
        border-radius: 6px;
        font-size: 13px;
        color: var(--text-dark);
        background: white;
    }

    .haziq-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .haziq-table th, .haziq-table td {
        text-align: left;
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-grey);
    }
    .haziq-table th { color: var(--text-grey); font-weight: 600; font-size: 12px; text-transform: uppercase; }
    .haziq-table tbody tr:hover { background: var(--page-bg); }

    .haziq-actions { display: flex; gap: 8px; }
    .haziq-actions form { display: inline; }
    .haziq-actions button {
        font-size: 12.5px;
        padding: 5px 10px;
    }

    .haziq-repeatable-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 10px;
        padding: 12px;
        border: 1px solid var(--border-grey);
        border-radius: 8px;
        margin-bottom: 10px;
    }

    /* The exact field/section/document an approver flagged, on the
       student's edit form — same red already used for every other
       "needs attention" state (status-badge.grace_expired etc.), just
       applied to an input's border instead of a badge's background. */
    .haziq-flagged {
        border: 2px solid #D0342C !important;
        background: #FDEEED;
        border-radius: 6px;
    }
    .haziq-flag-reason {
        color: #D0342C;
        font-size: 12.5px;
        margin: -6px 0 10px;
    }
    .haziq-flagged-section {
        border: 1px solid #D0342C;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 4px;
    }

    /* Candidature-terminated warning on the student's own Stage Gate
       page — same red as every other "needs attention" state here. */
    .alert-banner {
        border-radius: 8px;
        padding: 12px 16px;
        margin: 4px 0 16px;
        font-size: 13.5px;
    }
    .alert-banner-danger {
        background: #FDEEED;
        border: 1px solid #D0342C;
        color: #8A1F1A;
    }
    /* Document completeness on the student's own application page -- an
       informational notice, never a block, so it reads as a lighter
       amber than the termination danger banner above. */
    .alert-banner-warning {
        background: var(--warning-bg);
        border: 1px solid var(--warning-border);
        color: var(--warning-fg);
    }
</style>
