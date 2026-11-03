{{--
    The few things Chloe's screens need that the shell does not already have:
    the seat map, the block/room tiles, radio groups and compact table forms.
    Everything else (cards, tables, facts, badges, buttons, messages) is the
    shared shell in layout.css. Tokens only, so both themes work with no
    second rule. Scoped under chloe-/seat- names so nothing leaks out.
--}}
<style>
    /* Cards stacked down a page. */
    .chloe-stack { display: grid; gap: var(--space-6); }
    /* Two classes to beat uresearch.css's 680px .card-wide cap. layout.css
       lifts it only for a card that is a DIRECT child of
       .card-container-inline; inside this wrapper a card is a grandchild,
       and without this it snaps back to 680px on a 1900px screen. */
    .chloe-stack > .card,
    .chloe-stack > .card.card-wide { margin: 0; max-width: none; width: 100%; }
    .chloe-stack .card > h3:first-child { margin-top: 0; }

    .chloe-stats { --sdash-gap: var(--space-4); justify-content: flex-start; margin-bottom: var(--space-6); }
    .chloe-stats .sdash-stat { max-width: none; }

    .chloe-eyebrow {
        margin: var(--space-6) 0 var(--space-2);
        font-size: var(--text-xs);
        font-weight: var(--weight-semi);
        letter-spacing: var(--tracking-caps);
        text-transform: uppercase;
        color: var(--text-grey);
    }

    .chloe-actions { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; margin-top: var(--space-4); }
    .chloe-actions form { margin: 0; }

    /* Radio groups: options side by side, each a normal-weight label. */
    .chloe-choices { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-6); margin: var(--space-2) 0 var(--space-3); }
    .chloe-choices.is-stacked { flex-direction: column; }
    .chloe-choices label {
        display: flex; align-items: center; gap: var(--space-2);
        margin: 0; font-weight: var(--weight-normal); color: var(--text-dark);
    }

    /* Repeating publication rows on the appeal form. */
    .chloe-row { display: flex; gap: var(--space-2); margin-bottom: var(--space-2); }
    .chloe-row input { flex: 1; margin: 0; }

    /* Compact forms inside a table cell. */
    .chloe-inline { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: center; }
    .chloe-inline input, .chloe-inline select { width: auto; min-width: 0; margin: 0; }
    .chloe-inline input.is-narrow { width: 5rem; }

    /* Block and room pickers. */
    .chloe-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--space-4); }
    a.chloe-tile {
        display: flex; flex-direction: column; gap: var(--space-2);
        padding: var(--space-5);
        background: var(--surface);
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-md);
        color: var(--text-dark);
        text-decoration: none;
        transition: border-color var(--duration-fast) var(--ease), box-shadow var(--duration-fast) var(--ease);
    }
    a.chloe-tile:hover { border-color: var(--navy); box-shadow: var(--shadow-md); }
    .chloe-tile-title { font-size: var(--text-lg); font-weight: var(--weight-semi); color: var(--navy); }
    .chloe-tile-meta { font-size: var(--text-sm); color: var(--text-grey); }

    .chloe-tile-foot { display: flex; align-items: baseline; gap: var(--space-2); margin-top: auto; }
    .chloe-tile-count { font-size: var(--text-lg); font-weight: var(--weight-semi); font-variant-numeric: tabular-nums; }
    .chloe-tile-count.tone-good     { color: var(--success-fg); }
    .chloe-tile-count.tone-warn     { color: var(--warning-fg); }
    .chloe-tile-count.tone-critical { color: var(--danger-fg); }

    /* Free-seat bar on a room tile. */
    .chloe-meter { display: block; height: 6px; background: var(--surface-sunken); border-radius: var(--radius-full); overflow: hidden; }
    .chloe-meter > span { display: block; height: 100%; border-radius: inherit; }
    .chloe-meter.tone-good > span     { background: var(--success-fg); }
    .chloe-meter.tone-warn > span     { background: var(--warning-fg); }
    .chloe-meter.tone-critical > span { background: var(--danger-fg); }

    /* One block of rooms on the Workstation home screen. */
    .chloe-block { margin-bottom: var(--space-8); }
    .chloe-block-head {
        display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap;
        margin-bottom: var(--space-3); padding-bottom: var(--space-2); border-bottom: 1px solid var(--border-subtle);
    }
    .chloe-block-head h3 { margin: 0; font-size: var(--text-lg); color: var(--text-dark); }

    /* The seat a student holds: one strip, seat number large on the left. */
    .chloe-held {
        display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-6);
        margin-bottom: var(--space-6); padding: var(--space-5) var(--space-6);
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-left: 4px solid var(--success-fg); border-radius: var(--radius-md); box-shadow: var(--shadow-sm);
    }
    .chloe-held-seat { display: flex; flex-direction: column; }
    .chloe-held-seat .chloe-eyebrow { margin: 0; }
    .chloe-held-code { font-size: var(--text-3xl); font-weight: var(--weight-bold); color: var(--navy); line-height: 1; font-variant-numeric: tabular-nums; }
    .chloe-held-facts { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-8); margin: 0; flex: 1 1 320px; }
    .chloe-held-facts dt { font-size: var(--text-xs); color: var(--text-grey); text-transform: uppercase; letter-spacing: var(--tracking-caps); }
    .chloe-held-facts dd { margin: 0; font-weight: var(--weight-semi); color: var(--text-dark); }
    .chloe-held-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .chloe-held-actions form { margin: 0; }

    /* A small setting tucked into the page header (the gender filter). */
    .chloe-setting { position: relative; }
    .chloe-setting > summary { list-style: none; }
    .chloe-setting > summary::-webkit-details-marker { display: none; }
    .chloe-setting[open] > form {
        position: absolute; right: 0; top: calc(100% + var(--space-2)); z-index: var(--z-overlay);
        padding: var(--space-3); background: var(--surface-raised);
        border: 1px solid var(--border-grey); border-radius: var(--radius-md); box-shadow: var(--shadow-lg);
        flex-wrap: nowrap;
    }

    /* A room, collapsed, on the CGS operations screen. */
    .chloe-room { border: 1px solid var(--border-subtle); border-radius: var(--radius-md); margin-bottom: var(--space-3); }
    .chloe-room > summary {
        display: flex; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap;
        padding: var(--space-3) var(--space-4); cursor: pointer; font-weight: var(--weight-semi); color: var(--text-dark);
    }
    .chloe-room[open] > summary { border-bottom: 1px solid var(--border-subtle); background: var(--surface-sunken); }
    .chloe-room > .table-scroll { padding: var(--space-3) var(--space-4); }

    /* ---- seat map ----
       Status pairs (-bg / -fg / -border) are contrast-checked together, so
       a seat is legible in both themes without a colour of its own. */
    .seat {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 48px; height: 40px; padding: 0 var(--space-2);
        border: 1px solid; border-radius: var(--radius-sm);
        font-size: var(--text-sm); font-weight: var(--weight-semi);
        font-variant-numeric: tabular-nums; line-height: 1;
    }
    .seat.is-available { background: var(--success-bg); color: var(--success-fg); border-color: var(--success-border); }
    .seat.is-occupied  { background: var(--danger-bg);  color: var(--danger-fg);  border-color: var(--danger-border); }
    .seat.is-disabled  { background: var(--warning-bg); color: var(--warning-fg); border-color: var(--warning-border); }
    .seat.is-reserved  { background: var(--surface-sunken); color: var(--text-grey); border-color: var(--border-grey); }
    .seat.is-muted     { opacity: 0.55; }

    button.seat.is-available { cursor: pointer; box-shadow: none; }
    button.seat.is-available:hover { border-color: var(--success-fg); box-shadow: var(--shadow-sm); background: var(--success-bg); }

    .seat-legend { display: flex; flex-wrap: wrap; gap: var(--space-4); margin: 0 0 var(--space-4); font-size: var(--text-sm); color: var(--text-grey); }
    .seat-legend span { display: inline-flex; align-items: center; gap: var(--space-2); }
    .seat-legend .seat { min-width: 14px; height: 14px; padding: 0; }

    .seat-map {
        display: flex; flex-wrap: wrap; gap: var(--space-4);
        padding: var(--space-4);
        background: var(--surface-sunken);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
    }
    .seat-cluster { display: flex; flex-wrap: wrap; gap: var(--space-2); align-content: flex-start; align-items: flex-start; }
    .seat-map .seat-cluster { gap: var(--space-1); }
    .seat-map .seat { min-width: 24px; height: 22px; padding: 0 var(--space-1); font-size: var(--text-2xs); }
    .seat-cluster form { margin: 0; display: flex; }
    button.seat { margin: 0; }

    /* A notice above the room list needs air before the first block. */
    .card-container-inline > .message-info { margin-bottom: var(--space-6); }
</style>
