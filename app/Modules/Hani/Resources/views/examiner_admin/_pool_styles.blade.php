{{-- What both lists of the pool share: tabs, filter bar, table card, badges,
     avatar and pagination. Tokens only, so it follows the dark theme. Page-scoped
     by being included, not global: nothing here touches a Core stylesheet. --}}
<style>
    /* The internal/external split reads as two lists, so it gets a tab strip
       rather than a third dropdown sitting among the filters -- a filter you
       have to open to see the state of is the wrong control for the thing
       that decides which columns the table even has. */
    .exam-tabs {
        display: flex; gap: 4px; margin-bottom: 18px;
        border-bottom: 1px solid var(--border-grey);
    }
    .exam-tab {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 16px; margin-bottom: -1px;
        border: 1px solid transparent; border-bottom: none;
        border-radius: 10px 10px 0 0;
        font-size: var(--text-sm); font-weight: 600;
        color: var(--text-grey); text-decoration: none;
    }
    .exam-tab:hover { color: var(--navy); background: var(--surface-hover); }
    .exam-tab.is-current {
        background: var(--surface); color: var(--navy);
        border-color: var(--border-grey);
        box-shadow: inset 0 2px 0 var(--navy);
    }
    .exam-tab-count {
        padding: 1px 8px; border-radius: 999px;
        background: var(--border-subtle); color: var(--text-grey);
        font-size: var(--text-2xs); font-weight: 700;
    }
    .exam-tab.is-current .exam-tab-count { background: var(--accent-subtle); color: var(--navy); }

    /* The filter bar is its own surface, so it reads as a control strip over
       the table rather than as more page content. */
    .examiner-filters {
        display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        margin-bottom: 16px; padding: 14px 16px;
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 12px;
    }
    .examiner-filters .field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .examiner-filters label { font-size: var(--text-xs); color: var(--text-grey); font-weight: 600; margin: 0; }
    .examiner-filters select,
    .examiner-filters input[type="text"] {
        margin: 0; padding: 8px 10px; font-size: var(--text-sm); min-width: 170px;
    }
    .examiner-filters .search-field { flex: 1 1 260px; }
    .examiner-filters .search-field input { min-width: 0; width: 100%; }
    .examiner-filters button { margin: 0; padding: 9px 18px; }
    .examiner-filters .clear-link { align-self: center; font-size: var(--text-sm); color: var(--text-grey); }

    .exam-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        padding: 4px 16px 16px;
        overflow-x: auto;
    }

    .exam-table { width: 100%; }
    .exam-table th, .exam-table td { vertical-align: middle; }
    .examiner-avatar {
        display: inline-flex; align-items: center; justify-content: center;
        width: calc(var(--text-md) * 2.3); height: calc(var(--text-md) * 2.3);
        border-radius: 50%; flex-shrink: 0;
        background: var(--light-blue-bg); color: var(--navy);
        font-size: var(--text-sm); font-weight: 700;
    }
    .examiner-name-cell { display: flex; align-items: center; gap: 10px; min-width: 190px; }

    .examiner-state-badge {
        display: inline-block; padding: 3px 10px; border-radius: 999px;
        font-size: var(--text-xs); font-weight: 600; white-space: nowrap;
    }
    /* Tokens, not the literals this block used to carry -- the old hexes were
       light-mode values and stayed light on the dark theme. */
    .examiner-state-badge.tone-good     { background: var(--success-bg); color: var(--success-fg); }
    .examiner-state-badge.tone-warn     { background: var(--warning-bg); color: var(--warning-fg); }
    .examiner-state-badge.tone-info     { background: var(--info-bg); color: var(--info-fg); }
    .examiner-state-badge.tone-critical { background: var(--danger-bg); color: var(--danger-fg); }

    .examiner-pagination { display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
    .examiner-pagination a, .examiner-pagination span {
        padding: 6px 11px; border-radius: 8px; font-size: var(--text-sm); border: 1px solid var(--border-grey);
    }
    .examiner-pagination a { color: var(--navy); text-decoration: none; }
    .examiner-pagination a:hover { background: var(--surface-hover); border-color: var(--navy); }
    .examiner-pagination .is-current { background: var(--navy); color: var(--text-on-accent); border-color: var(--navy); }
    .examiner-pagination .is-disabled { color: var(--text-grey); }

    @media (max-width: 720px) {
        .examiner-filters select, .examiner-filters input[type="text"] { min-width: 0; width: 100%; }
        .examiner-filters .field { flex: 1 1 100%; }
    }
</style>
