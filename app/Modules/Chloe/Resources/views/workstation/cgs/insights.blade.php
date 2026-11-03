@extends('core::layouts.app')

@section('title', 'Workstation Insights')

@section('content')
    @include('chloe::workstation._assistant-widget')

    {{--
        Round 6: third attempt at this page -- a fixed 12-column grid (no
        CSS multi-column, no auto-flow), Power-BI-style two-layer
        filter+highlight interaction, and one design-token system shared
        by every chart. Every class on this page is prefixed "wsi-" and
        every control/heading/link below gets its colour, background,
        border, padding, font and line-height written out explicitly, so
        a shared stylesheet rule can never reach in and change it again
        (see the module README's note on the Round 5 donut-legend bug).

        ONE flat fact table (one row per seat, no PII) plus a small meta
        object are embedded below as inert JSON; every number, bar and
        chart on this page is built and re-built client-side from that
        JSON by public/js/chloe-workstation-insights.js, using
        public/js/chloe-insights-aggregate.js (pure, no DOM) for the
        filter/highlight math. No <canvas>, no external library, no
        inline style="" or event-handler attribute anywhere in this file
        -- the two <script src> tags are same-origin (CSP's script-src
        'self' already allows that with no nonce), and the embedded JSON
        blocks are inert data, never executed as script.
    --}}
    <style>
        /* ---- PART 1: design tokens --------------------------------- */
        #wsi-page {
            --wsi-blue: #004B85;
            --wsi-blue-2: #0066B3;
            --wsi-gold: #C88A12;
            --wsi-orange: #F68B33;
            --wsi-red: #C62828;
            --wsi-green: #2E8B57;
            --wsi-slate: #606C73;
            {{-- Round 7 PART 3.2: one-shade-darker hover tokens, same hue, never a different colour family. --}}
            --wsi-blue-hover: #003A68;
            --wsi-blue-2-hover: #00518E;
            --wsi-gold-hover: #A6730D;
            --wsi-orange-hover: #D66F1F;
            --wsi-red-hover: #A31F1F;
            --wsi-green-hover: #246E46;
            --wsi-slate-hover: #4C555C;
            --wsi-track: #E4E4DA;
            --wsi-grid: #E9E9E1;
            --wsi-text-dark: #2A2A2A;
            --wsi-text-body: #49505C;
            --wsi-text-grey: #656E7E;
            --wsi-border: #E0E3EA;
            --wsi-border-strong: #CBD1DB;
            --wsi-surface: #FFFFFF;
            --wsi-surface-sunken: #F8F9FB;
            --wsi-chip-bg: #E6EEF6;
            {{-- Round 7 PART 3.3: KPI/card/tile hover+selected tokens -- light shades only, never dark/navy. --}}
            --wsi-hover-bg: #F4F7FA;
            --wsi-hover-border: #C9D6E3;
            --wsi-selected-bg: #E6EEF6;
            --wsi-tooltip-text: #1F2933;
            --wsi-font: 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;

            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px 160px;
            font-family: var(--wsi-font);
            color: var(--wsi-text-body);
        }
        {{--
            Round 7 PART 3.1: blanket no-outline/no-ring rule for every
            interactive element on this page -- the user explicitly does
            not want any outline, stroke or box-shadow ring on click,
            selection or focus anywhere; selection is communicated ONLY
            by the highlight (colour/opacity), hover/focus ONLY by the
            tokens above. :focus-visible gets the SAME treatment as
            :hover (see each component below), never a ring.
        --}}
        #wsi-page button, #wsi-page a, #wsi-page select, #wsi-page [tabindex] {
            outline: none;
        }
        #wsi-page button:focus, #wsi-page a:focus, #wsi-page select:focus, #wsi-page [tabindex]:focus,
        #wsi-page button:focus-visible, #wsi-page a:focus-visible, #wsi-page select:focus-visible, #wsi-page [tabindex]:focus-visible {
            outline: none;
            box-shadow: none;
        }
        #wsi-page, #wsi-page * { box-sizing: border-box; }
        #wsi-page h1, #wsi-page h2, #wsi-page h3, #wsi-page h4 {
            font-family: var(--wsi-font); font-weight: 600; color: var(--wsi-text-dark); margin: 0;
        }
        /*
            Decorative text (value labels, axis ticks, category labels)
            must never intercept a click meant for the bar/segment under
            it -- found live: a percentage label painted on top of a
            stacked-bar segment silently ate every click on that
            segment. wireClickable() explicitly re-enables pointer
            events on any text that IS itself the clickable mark (e.g.
            a block/gender row label).
        */
        #wsi-page svg text { font-family: var(--wsi-font); letter-spacing: normal; font-variant-numeric: tabular-nums; pointer-events: none; }
        #wsi-page svg text.wsi-mark { pointer-events: auto; }
        #wsi-page .wsi-tabular { font-variant-numeric: tabular-nums; }

        .wsi-meta { margin: -8px 0 16px; font-size: 14px; line-height: 1.5; color: var(--wsi-text-grey); font-family: var(--wsi-font); }
        .wsi-live-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        /* The shared button style adds a top/right margin; inside this row the controls sit level with the text. */
        .wsi-live-row .wsi-link { margin: 0; display: inline-block; }
        .wsi-live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #2E8B57; }
        .wsi-live-dot.wsi-live-paused { background: #9CA3AF; }
        .wsi-live-dot.wsi-live-error { background: #C88A12; }
        .wsi-live-note { color: #606C73; margin-top: -12px; }
        .wsi-live-note[hidden] { display: none; }
        .wsi-link {
            font-family: var(--wsi-font); font-size: 13px; line-height: 1.4; font-weight: 400;
            color: var(--wsi-blue); background: none; border: none; margin: 0; padding: 0;
            text-decoration: underline; cursor: pointer;
        }
        .wsi-link:hover, .wsi-link:focus-visible { color: var(--wsi-blue-hover); }

        /* ---- Filter panel (matches the CGS landing page's own select look, explicitly -- not inherited) ---- */
        .wsi-filter-panel {
            background: var(--wsi-surface); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 24px; margin-bottom: 24px;
        }
        .wsi-filter-row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .wsi-field { display: flex; flex-direction: column; gap: 4px; min-width: 160px; flex: 1 1 180px; }
        .wsi-field label {
            font-family: var(--wsi-font); font-size: 12px; line-height: 1.4; font-weight: 500;
            color: var(--wsi-text-grey); margin: 0; padding: 0; background: none; border: none;
        }
        .wsi-select {
            width: 100%; margin: 0; font-family: var(--wsi-font); font-size: 14px; line-height: 1.55;
            color: var(--wsi-text-dark); background-color: var(--wsi-surface-sunken);
            border: 1px solid var(--wsi-border); border-radius: 6px; padding: 12px 40px 12px 12px;
            appearance: none; -webkit-appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238A94A6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 16px 16px;
        }
        .wsi-select:hover { background-color: var(--wsi-hover-bg); border-color: var(--wsi-hover-border); }
        .wsi-select:focus-visible { background-color: var(--wsi-selected-bg); border-color: var(--wsi-hover-border); }
        .wsi-reset-link { align-self: center; margin: 0; padding-bottom: 12px; }
        @media (max-width: 1099px) { .wsi-filter-row { flex-wrap: wrap; } }

        /* ---- Highlight strip (Power BI's cross-highlight state) ---- */
        {{--
            Final polish PART 1.1 (found live): the strip used to be
            ALWAYS rendered, 48px tall, with an empty white bar and grey
            hint text sitting there even with nothing selected. Now it
            renders nothing at all -- [hidden]{display:none} below -- in
            that state, and a plain, boxless caption under the filter
            panel (.wsi-strip-tip) carries the same hint permanently.
            The strip itself only mounts (and only then takes any space,
            shadow or padding) once a filter or a highlight is active.
        --}}
        .wsi-strip-tip {
            font-family: var(--wsi-font); font-size: 13px; line-height: 1.4; color: var(--wsi-text-grey);
            margin: 0 0 16px; padding: 0; background: none; border: none;
        }
        {{--
            Found live: the strip is 44px tall, box-sizing:border-box,
            with overflow:hidden (needed so the slide-in transition does
            not flash taller content during the transform). An 8px top
            + 8px bottom padding only leaves 28px of content height,
            which clips a 32px-tall chip by ~4px. Horizontal padding
            only (as the spec calls for), with the chips vertically
            centred by align-items, leaves a full 32px for the chip with
            6px to spare top and bottom.
        --}}
        .wsi-strip {
            position: sticky; top: 0; z-index: 25;
            background: var(--wsi-surface); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 0 16px; margin-bottom: 8px; height: 44px; box-sizing: border-box;
            display: flex; align-items: center; gap: 12px; flex-wrap: nowrap; overflow: hidden;
            opacity: 1; transform: translateY(0);
        }
        .wsi-strip[hidden] { display: none; }
        @media (prefers-reduced-motion: no-preference) {
            .wsi-strip { transition: opacity 150ms ease-out, transform 150ms ease-out; }
            .wsi-strip.wsi-strip-entering { opacity: 0; transform: translateY(-6px); }
        }
        {{--
            Found live at 390px: a single 44px row has no room for a
            chip AND "Clear all" AND the summary once the chip's own
            text needs real width -- flex-wrap:nowrap made the summary
            text run straight through the chip instead of ever wrapping.
            Below 480px the row grows (auto height) and wraps onto as
            many lines as the content needs, with nothing clipped.
        --}}
        @media (max-width: 480px) {
            .wsi-strip { height: auto; min-height: 44px; flex-wrap: wrap; overflow: visible; padding: 8px 16px; }
            .wsi-strip-right { width: 100%; justify-content: space-between; margin-left: 0; }
        }
        .wsi-chip-strip { display: flex; flex-wrap: wrap; gap: 8px; flex: 1 1 auto; min-width: 0; }
        .wsi-chip {
            display: inline-flex; align-items: center; gap: 2px; height: 32px;
            border: 1px solid #C9D6E3; background: #E6EEF6;
            color: var(--wsi-text-dark); border-radius: 8px; padding: 0 4px 0 12px;
            font-family: var(--wsi-font); font-size: 13px; line-height: 1.3; max-width: 320px;
        }
        .wsi-chip-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 40ch; }
        .wsi-chip-x {
            width: 24px; height: 24px; flex-shrink: 0; border: none; background: none; cursor: pointer;
            color: var(--wsi-text-grey); font-family: var(--wsi-font); font-size: 15px; line-height: 1;
            border-radius: 50%; padding: 0; margin: 0 0 0 2px;
        }
        .wsi-chip-x:hover, .wsi-chip-x:focus-visible { background: var(--wsi-hover-bg); color: var(--wsi-text-dark); }
        .wsi-strip-right { display: flex; align-items: center; gap: 14px; flex-shrink: 0; margin-left: auto; }
        .wsi-summary { font-family: var(--wsi-font); font-size: 13px; line-height: 1.4; color: var(--wsi-text-body); white-space: nowrap; }

        .wsi-selected-panel { background: var(--wsi-surface); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 24px; overflow: hidden; }
        .wsi-selected-panel[hidden] { display: none; }
        .wsi-selected-toggle {
            width: 100%; text-align: left; display: flex; align-items: center; justify-content: space-between;
            font-family: var(--wsi-font); font-size: 14px; font-weight: 600; line-height: 1.4; color: var(--wsi-text-dark);
            background: none; border: none; margin: 0; padding: 16px 24px; cursor: pointer;
        }
        .wsi-selected-toggle:hover, .wsi-selected-toggle:focus-visible { background: var(--wsi-hover-bg); }
        .wsi-selected-body { padding: 0 24px 24px; }
        .wsi-selected-body[hidden] { display: none; }
        .wsi-selected-table { width: 100%; border-collapse: collapse; font-family: var(--wsi-font); font-size: 13px; }
        .wsi-selected-table th { text-align: left; font-weight: 600; color: var(--wsi-text-grey); font-size: 12px; padding: 6px 8px; border-bottom: 1px solid var(--wsi-border); }
        .wsi-selected-table td { padding: 8px; border-bottom: 1px solid var(--wsi-border); color: var(--wsi-text-body); }
        {{--
            Round 8 PART 3.1 follow-up (found live at 390px): a plain
            auto-layout table never shrinks a column below the width of
            its longest unbroken word ("Termination", "Projected"), so
            the forecast's narrow fallback table was forcing the whole
            card past its own container even with no overflow-x left to
            absorb it. table-layout:fixed + word-break on this table
            ONLY makes every column share the table's own 100% width and
            wrap long words instead, so it can never push past its
            parent -- same explicit colours/border/padding as the base
            .wsi-selected-table rules above, just a width-safe layout.
        --}}
        .wsi-forecast-table { table-layout: fixed; }
        .wsi-forecast-table th, .wsi-forecast-table td { word-break: break-word; font-size: 11px; padding: 6px 4px; }
        .wsi-selected-pager { display: flex; align-items: center; gap: 12px; margin-top: 12px; }
        .wsi-page-btn {
            font-family: var(--wsi-font); font-size: 13px; font-weight: 600; line-height: 1.2; color: #fff;
            background: var(--wsi-blue); border: 1px solid transparent; border-radius: 6px; margin: 0; padding: 8px 16px; cursor: pointer;
        }
        .wsi-page-btn:disabled { background: var(--wsi-track); color: var(--wsi-text-grey); cursor: not-allowed; }
        .wsi-page-btn:hover:not(:disabled), .wsi-page-btn:focus-visible:not(:disabled) { background: var(--wsi-blue-hover); }

        /* ---- Fixed 12-column grid (PART 4) -------------------------- */
        .wsi-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 24px; align-items: stretch; }
        .wsi-grid > * { min-width: 0; }
        .wsi-span-12 { grid-column: span 12; }
        .wsi-span-7 { grid-column: span 7; }
        .wsi-span-6 { grid-column: span 6; }
        .wsi-span-5 { grid-column: span 5; }
        @media (max-width: 1099px) {
            .wsi-grid > * { grid-column: span 12 !important; }
        }

        .wsi-layer-head { grid-column: span 12; margin: 32px 0 0; }
        .wsi-layer-head:first-child { margin-top: 0; }
        .wsi-layer-title { font-size: 20px; font-weight: 600; color: var(--wsi-text-dark); margin: 0 0 2px; }
        .wsi-layer-desc { font-size: 13px; color: var(--wsi-text-grey); margin: 0; font-family: var(--wsi-font); }

        .wsi-card { background: var(--wsi-surface); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 24px; height: 100%; display: flex; flex-direction: column; min-width: 0; }
        .wsi-card-title { font-size: 18px; font-weight: 600; color: var(--wsi-text-dark); margin: 0 0 2px; }
        .wsi-card-subtitle { font-size: 13px; font-weight: 400; color: var(--wsi-slate); margin: 0 0 16px; font-family: var(--wsi-font); }
        .wsi-card-head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        {{--
            min-width: 0 on both the flex container and this flex child
            -- found live: a flex child's default min-width is "auto",
            which refuses to shrink below its content's own intrinsic
            width (here, the forecast's narrow-width fallback TABLE,
            whose columns have no explicit width). That silently defeated
            #wsi-forecast's own overflow-x:auto below -- the flex chain
            grew the whole card, and the whole page, instead of ever
            showing a scrollbar.
        --}}
        .wsi-card-body { flex: 1 1 auto; min-width: 0; }
        {{--
            Round 8 PART 3.1: the forecast chart is now ALWAYS built at
            its container's own real pixel width (columns + gaps fill
            it exactly; the default grain auto-escalates to a coarser
            one before building if a finer one would not fit) -- no
            overflow-x here any more, since there is deliberately never
            anything to scroll.
        --}}
        /*
            Found live: "Which rooms are filling up?" (many rows) sits
            next to "Male vs female rooms" (two rows) -- grid-stretching
            the short card to match the tall one's natural height left
            a 250px+ blank area at its bottom. Bounding the room LIST's
            own height (not the card's padding) keeps both cards'
            natural heights within the spec's 80px rule; every room
            stays reachable by scrolling this one list.
        */
        {{--
            Round 8 PART 2.1: scrolling must be OBVIOUS, not just
            possible -- a permanently visible, styled scrollbar (some
            platforms hide overlay scrollbars entirely until the user
            starts dragging, which looked like a plain cut-off list), a
            soft fade at the bottom edge that disappears once scrolled
            to the end, and a caption underneath.
        --}}
        .wsi-rooms-scroll { position: relative; margin-bottom: 12px; }
        {{--
            Round 8: Gender became a grouped bar chart, and at this
            card's own column width (span-5, always under the 700px
            narrow threshold -- measured live at ~330px even at a
            1280px viewport) it always renders in label-above-bar mode,
            making its natural height ~720px. Found live: Rooms' old
            220px cap left a 260px+ blank area under its own legend
            once grid-stretched to match that. 560px (close to the
            current 10-room list's own full height) keeps the two
            cards' natural heights within the spec's 80px row-parity
            rule again, while the scroll/fade/caption stay functionally
            real for whenever the room count grows past what fits.
        --}}
        .wsi-rooms-scroll-inner {
            max-height: 560px; overflow-y: auto;
            scrollbar-width: thin; scrollbar-color: #9AA7B4 #EEF1F5;
        }
        .wsi-rooms-scroll-inner::-webkit-scrollbar { width: 10px; }
        .wsi-rooms-scroll-inner::-webkit-scrollbar-track { background: #EEF1F5; }
        .wsi-rooms-scroll-inner::-webkit-scrollbar-thumb { background: #9AA7B4; border-radius: 5px; }
        .wsi-rooms-sticky-header {
            position: sticky; top: 0; z-index: 2; height: 32px; display: flex; align-items: center;
            background: var(--wsi-surface); font-size: 13px; font-weight: 600; line-height: 1.4; color: var(--wsi-slate);
            font-family: var(--wsi-font);
        }
        .wsi-scroll-fade {
            position: absolute; left: 0; right: 10px; bottom: 0; height: 24px;
            background: linear-gradient(to bottom, rgba(255,255,255,0), #FFFFFF);
            pointer-events: none;
        }
        .wsi-scroll-caption { font-size: 12px; color: var(--wsi-text-grey); margin: 0 0 12px; font-family: var(--wsi-font); }
        {{--
            Final polish PART 1.4 (found live): the Programmes,
            Candidacy and Length-of-stay charts already scroll the CARD
            BODY horizontally on a narrow container (so a long label or
            tight column never silently clips) -- but that scrolling had
            no visible cue at all, same problem Round 8 fixed for the
            Rooms card's vertical scroll. .wsi-hscroll is added by the
            JS only on the cards that actually need it (never a style
            change to the forecast or selected-seats table, which
            Round 8 made deliberately non-scrolling).
        --}}
        .wsi-hscroll {
            overflow-x: auto; scrollbar-width: thin; scrollbar-color: #9AA7B4 #EEF1F5;
        }
        .wsi-hscroll::-webkit-scrollbar { height: 10px; }
        .wsi-hscroll::-webkit-scrollbar-track { background: #EEF1F5; }
        .wsi-hscroll::-webkit-scrollbar-thumb { background: #9AA7B4; border-radius: 5px; }
        .wsi-empty { color: var(--wsi-text-grey); font-size: 14px; font-family: var(--wsi-font); margin: 24px 0; text-align: center; }
        .wsi-footer-note { font-size: 12px; color: var(--wsi-text-grey); margin: 12px 0 0; font-family: var(--wsi-font); }
        .wsi-footer-link { margin: 12px 0 0; }

        /* ---- KPI row (its own internal grid, inside one span-12 cell) ---- */
        .wsi-kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
        @media (max-width: 1099px) { .wsi-kpi-row { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 699px) { .wsi-kpi-row { grid-template-columns: 1fr; } }
        {{--
            Round 7 PART 3.3: this is the card the user specifically
            complained about -- hovering it turned navy with grey text.
            Root cause: the shared stylesheet's own `button:hover`
            sets background-color, and the old `.wsi-kpi-card:hover`
            rule only set box-shadow, never background-color itself --
            CSS specificity is resolved PER PROPERTY, so the property
            this rule left unset still fell through to the shared
            button's own (higher-reaching) hover rule. Every state
            below now sets background, border and colour explicitly, so
            nothing can ever fall through again.
        --}}
        {{-- Shared css/uresearch.css also makes every button `display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px`, which laid a card's value, label and link out as one sideways row. Stacked block layout and normal weight are written out explicitly here. --}}
        .wsi-kpi-card {
            display: block; align-items: normal; justify-content: normal; font-size: 13px; font-weight: 400;
            min-width: 0; text-align: left; font-family: var(--wsi-font); color: var(--wsi-text-body);
            background: var(--wsi-surface); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin: 0; padding: 16px 20px; border: 1px solid transparent; border-left: 4px solid var(--wsi-border-strong);
            cursor: pointer; line-height: 1.3; outline: none;
        }
        .wsi-kpi-card:hover {
            background: var(--wsi-hover-bg); border-color: var(--wsi-hover-border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.10); color: var(--wsi-text-body);
        }
        .wsi-kpi-card:focus-visible, .wsi-kpi-card.wsi-selected {
            background: var(--wsi-selected-bg); border-color: var(--wsi-hover-border); color: var(--wsi-text-body);
        }
        .wsi-kpi-red { border-left-color: var(--wsi-red); }
        .wsi-kpi-gold { border-left-color: var(--wsi-gold); }
        .wsi-kpi-orange { border-left-color: var(--wsi-orange); }
        .wsi-kpi-blue { border-left-color: var(--wsi-blue); }
        .wsi-kpi-value { font-size: 28px; font-weight: 700; color: var(--wsi-text-dark); line-height: 1.1; font-variant-numeric: tabular-nums; }
        .wsi-kpi-value-ghost { font-size: 15px; font-weight: 400; color: var(--wsi-text-grey); margin-left: 6px; }
        .wsi-kpi-label { font-size: 13px; color: var(--wsi-text-grey); margin-top: 4px; font-family: var(--wsi-font); }
        .wsi-kpi-view { font-size: 12px; margin-top: 8px; display: inline-block; color: var(--wsi-blue); }
        .wsi-kpi-view:hover, .wsi-kpi-view:focus-visible { color: var(--wsi-blue-hover); }
        .wsi-kpi-empty { grid-column: 1 / -1; text-align: center; color: var(--wsi-text-grey); font-size: 14px; padding: 24px; font-family: var(--wsi-font); }
        .wsi-kpi-key { font-size: 12px; color: var(--wsi-text-grey); margin: 10px 0 24px; font-family: var(--wsi-font); }

        {{-- Round 7 PART 4.4: the "Key returns" tiles -- same hover/selected treatment as a KPI card, in a real class (not inline styles) this time. --}}
        .wsi-tile-row { display: flex; gap: 14px; }
        .wsi-tile {
            display: block; align-items: normal; justify-content: normal; font-size: 12px; font-weight: 400;
            flex: 1 1 0; min-width: 0; text-align: center; font-family: var(--wsi-font); color: var(--wsi-text-body);
            background: var(--wsi-surface-sunken); border-radius: 10px; margin: 0; padding: 16px 6px;
            border: 1px solid transparent; border-top: 4px solid var(--wsi-border-strong);
            cursor: pointer; outline: none;
        }
        .wsi-tile:hover { background: var(--wsi-hover-bg); border-color: var(--wsi-hover-border); }
        .wsi-tile:focus-visible, .wsi-tile.wsi-selected { background: var(--wsi-selected-bg); border-color: var(--wsi-hover-border); }
        .wsi-tile-value { font-size: 26px; font-weight: 700; color: var(--wsi-text-dark); font-variant-numeric: tabular-nums; }
        .wsi-tile-value-urgent { color: var(--wsi-red); }
        .wsi-tile-ghost { display: block; font-size: 12px; font-weight: 400; color: var(--wsi-text-grey); margin-top: 2px; }
        {{-- 480px and below the three tiles are ~67px wide: tighter gap/padding and an 11px label so the word "Overdue" fits inside its own button. --}}
        @media (max-width: 480px) {
            .wsi-tile-ghost { display: none; }
            .wsi-tile-row { gap: 8px; }
            .wsi-tile { padding: 16px 3px; }
            .wsi-tile-label { font-size: 11px; }
        }
        .wsi-tile-label { font-size: 12px; color: var(--wsi-text-grey); margin-top: 4px; }

        /* ---- Shared chart primitives (PART 5) ------------------------ */
        .wsi-axis-label, .wsi-category-label { font-size: 12px; fill: var(--wsi-slate); }
        .wsi-value-label { font-size: 13px; font-weight: 600; fill: var(--wsi-text-dark); }
        .wsi-pill-m, .wsi-pill-f {
            display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px;
            border-radius: 50%; font-size: 10px; font-weight: 700; background: #ECEEF1; color: var(--wsi-text-body); flex-shrink: 0;
        }
        {{--
            table-layout: fixed is load-bearing here, not cosmetic --
            found live (a real 390px horizontal scroll bug, traced with
            a bisection script): a <table>'s default table-layout:auto
            computes column widths from CONTENT first and ignores an
            ancestor's explicit width:1px to fit them, so this "visually
            hidden" accessible data table was still rendering at its
            full ~450px natural width -- clipped from VIEW by
            overflow:hidden, but still counted toward the document's
            scrollable width. table-layout:fixed makes it actually
            respect the 1px box.
        --}}
        .wsi-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; table-layout: fixed; }
        {{--
            Round 7 PART 3.2: every clickable mark is an SVG <g class="wsi-mark">
            wrapping its own ghost + solid shapes (classes wsi-ghost-rect /
            wsi-solid-rect below) -- hover/focus-visible on the GROUP
            drives both via plain descendant selectors, so colour never
            needs re-painting from JS on hover (only on an actual
            filter/highlight change). No outline, ever; a ghost mark
            brightens by +10% opacity on hover/focus, a solid mark swaps
            to its one-shade-darker hover token -- never a ring, never a
            different colour family.
        --}}
        .wsi-mark { cursor: pointer; outline: none; }
        .wsi-mark:focus-visible { outline: none; }
        #wsi-page svg .wsi-ghost-rect { transition: fill-opacity 120ms ease; }
        #wsi-page svg .wsi-mark:hover .wsi-ghost-rect,
        #wsi-page svg .wsi-mark:focus-visible .wsi-ghost-rect { fill-opacity: 0.3; }
        #wsi-page svg .wsi-solid-rect { transition: fill 120ms ease; }
        #wsi-page svg .wsi-mark:hover .wsi-solid-rect,
        #wsi-page svg .wsi-mark:focus-visible .wsi-solid-rect { fill: var(--wsi-mark-hover-fill, inherit); }
        #wsi-page svg text.wsi-mark { transition: fill 120ms ease; }
        #wsi-page svg text.wsi-mark:hover, #wsi-page svg text.wsi-mark:focus-visible { fill: var(--wsi-blue-hover); }
        .wsi-group-head { font-size: 13px; font-weight: 600; color: var(--wsi-text-grey); margin: 12px 0 6px; font-family: var(--wsi-font); }
        .wsi-group-head:first-child { margin-top: 0; }
        .wsi-room-link { color: var(--wsi-blue); text-decoration: underline; font-size: 13px; font-family: var(--wsi-font); }
        .wsi-room-link:hover, .wsi-room-link:focus-visible { color: var(--wsi-blue-hover); }
        {{-- Same link, but an SVG <text> -- fill, not color, is what actually paints it. --}}
        #wsi-page svg .wsi-room-link-text { fill: var(--wsi-blue); transition: fill 120ms ease; }
        #wsi-page svg a:hover .wsi-room-link-text, #wsi-page svg a:focus-visible .wsi-room-link-text { fill: var(--wsi-blue-hover); }

        {{--
            Final polish PART 1.2 (found live, again): the previous
            overflow:hidden-on-the-container design clipped a button's
            own top/bottom whenever its text needed more than one line
            at any width in between the wide and the 480px breakpoints.
            This version drops overflow:hidden entirely -- each button
            carries its OWN inner border-radius so the selected pill
            never needs the container to clip anything -- and sizes
            purely off height:100%/line-height/box-sizing so nothing can
            ever be cut regardless of width.
        --}}
        {{--
            LAYOUT FIX (segmented control). The shared stylesheet (css/uresearch.css, "Buttons")
            gives EVERY button `margin-top: 16px; margin-right: 8px` (plus padding, colour, font
            and radius). Our old `.wsi-segctl button` rule never set a margin, so each button
            was pushed 16px down inside its 32px-tall inner box and hung 13px below the rounded
            container. Every property the shared rule sets is now written explicitly on our own
            classes, with the selector `.wsi-segctl button` (class + element) outranking it, so
            nothing here depends on, or edits, the shared file.
        --}}
        .wsi-segctl {
            display: inline-flex; align-items: center; box-sizing: border-box; height: 40px; margin: 0; padding: 3px;
            background: #fff; border: 1px solid #C9D6E3; border-radius: 8px; overflow: visible;
            vertical-align: middle; flex-shrink: 0;
        }
        .wsi-segctl button {
            display: inline-flex; align-items: center; justify-content: center; align-self: auto; flex: 1 1 0;
            box-sizing: border-box; min-width: 72px; height: 100%; min-height: 0; margin: 0; padding: 0 14px;
            position: static; top: auto; transform: none; vertical-align: middle;
            font-family: var(--wsi-font); font-size: 13px; font-weight: 600; line-height: 1; white-space: nowrap;
            color: var(--wsi-text-grey); background: #fff; border: 1px solid transparent; border-radius: 6px;
            cursor: pointer; outline: none; box-shadow: none;
        }
        .wsi-segctl button:hover, .wsi-segctl button:focus-visible { background: var(--wsi-hover-bg); color: var(--wsi-text-dark); }
        .wsi-segctl button[aria-pressed="true"] { background: #004B85; color: #fff; }
        .wsi-segctl button[aria-pressed="true"]:hover, .wsi-segctl button[aria-pressed="true"]:focus-visible { background: var(--wsi-blue-hover); color: #fff; }
        {{--
            480px and below: the 72px floor does not fit this card's width next to its title, so the
            control takes its own full-width row and the three buttons share it equally.
        --}}
        @media (max-width: 480px) {
            .wsi-segctl { display: flex; width: 100%; }
            .wsi-segctl button { min-width: 0; padding: 0 6px; font-size: 13px; }
        }
        .wsi-breadcrumb { font-size: 13px; color: var(--wsi-text-grey); margin: 0 0 8px; font-family: var(--wsi-font); }
        .wsi-breadcrumb button { font-family: var(--wsi-font); font-size: 13px; color: var(--wsi-blue); background: none; border: none; margin: 0; padding: 0; text-decoration: underline; cursor: pointer; outline: none; }
        .wsi-breadcrumb button:hover, .wsi-breadcrumb button:focus-visible { color: var(--wsi-blue-hover); }
        .wsi-drill-btn {
            font-family: var(--wsi-font); font-size: 12px; font-weight: 600; line-height: 1.2; color: var(--wsi-blue);
            background: var(--wsi-chip-bg); border: 1px solid var(--wsi-blue); border-radius: 6px; padding: 6px 12px; cursor: pointer; margin: 8px 0 0 0; outline: none;
        }
        .wsi-drill-btn:hover, .wsi-drill-btn:focus-visible { background: var(--wsi-hover-border); border-color: var(--wsi-blue-hover); color: var(--wsi-blue-hover); }

        {{--
            Round 7 PART 5: solid white, explicit colours set on every
            child so nothing can inherit stale white text -- found live
            last round that a tooltip could render white-on-white/
            transparent. color/opacity/filter reset defensively so no
            ancestor can bleed through either.
        --}}
        .wsi-tooltip {
            position: fixed; pointer-events: none; z-index: 2147483000; max-width: 280px;
            background: #FFFFFF; border: 1px solid #D8D8C9; border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            font-family: var(--wsi-font); font-size: 13px; line-height: 1.4;
            padding: 10px 12px; color: var(--wsi-tooltip-text); opacity: 1; filter: none;
        }
        .wsi-tooltip[hidden] { display: none; }
        .wsi-tooltip-title { font-weight: 700; color: var(--wsi-tooltip-text); opacity: 1; }
        .wsi-tooltip-value { color: var(--wsi-tooltip-text); opacity: 1; margin-top: 2px; }
        .wsi-tooltip-sub { color: var(--wsi-slate); opacity: 1; font-size: 12px; margin-top: 4px; }

        {{-- Round 7 PART 4.1: one shared legend component, used by every chart that needs one. --}}
        .wsi-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; box-sizing: border-box; margin: 0 0 10px; padding: 0; font-family: var(--wsi-font); }
        .wsi-legend-caption { font-size: 12px; color: var(--wsi-text-grey); margin: 0 0 12px; font-family: var(--wsi-font); }
        .wsi-legend-item {
            display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--wsi-text-body);
            background: none; border: none; padding: 2px 4px; margin: 0; border-radius: 6px; cursor: pointer; outline: none; line-height: 1.4;
        }
        .wsi-legend-item:not(.wsi-legend-static):hover, .wsi-legend-item:not(.wsi-legend-static):focus-visible { background: var(--wsi-hover-bg); color: var(--wsi-text-dark); }
        .wsi-legend-item.wsi-selected { background: var(--wsi-selected-bg); color: var(--wsi-text-dark); }
        .wsi-legend-item.wsi-ghosted { color: var(--wsi-text-grey); }
        .wsi-legend-static { cursor: default; }
        .wsi-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; flex-shrink: 0; }
        .wsi-swatch-line { width: 16px; height: 2px; border-radius: 1px; display: inline-block; flex-shrink: 0; }
        .wsi-swatch-dashed { width: 16px; height: 0; border-top: 2px dashed currentColor; display: inline-block; flex-shrink: 0; }
    </style>

    <div class="wsi-page" id="wsi-page">
        <x-core::page-header title="Workstation Insights" :back="route('workstation.cgs.operations')">
            <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
        </x-core::page-header>

        {{-- Live indicator (replaces "Data as of ... / 5-minute stale warning"): the page polls
             the data version every 15 s and re-renders in place with the CURRENT filters and
             highlight. id="wsi-refresh-link" stays so the manual Refresh link keeps carrying the
             URL state the page script writes. --}}
        <p class="wsi-meta wsi-live-row">
            <span class="wsi-live-dot" id="wsi-live-dot" aria-hidden="true"></span>
            <span id="wsi-live-text">Live &middot; updated {{ now('Asia/Kuala_Lumpur')->format('H:i:s') }}</span>
            <button type="button" class="wsi-link" id="wsi-live-pause">Pause updates</button>
            &middot; <a class="wsi-link" id="wsi-refresh-link" href="{{ route('workstation.cgs.insights') }}">Refresh</a>
        </p>
        <p class="wsi-meta wsi-live-note" id="wsi-live-note" hidden></p>
        <div id="wsi-live-config" hidden
             data-version="{{ \App\Modules\Chloe\Support\WorkstationLiveVersion::current() }}"
             data-version-url="{{ route('workstation.cgs.live.version') }}"
             data-data-url="{{ route('workstation.cgs.live.insights') }}"
             data-interval="15000"></div>

        <div class="wsi-filter-panel">
            <div class="wsi-filter-row">
                <div class="wsi-field">
                    <label for="wsi-filter-block">Block</label>
                    <select class="wsi-select" name="block" id="wsi-filter-block" data-dim="block"></select>
                </div>
                <div class="wsi-field">
                    <label for="wsi-filter-room">Room</label>
                    <select class="wsi-select" name="room" id="wsi-filter-room" data-dim="room"></select>
                </div>
                <div class="wsi-field">
                    <label for="wsi-filter-gender">Gender</label>
                    <select class="wsi-select" name="gender" id="wsi-filter-gender" data-dim="gender"></select>
                </div>
                <div class="wsi-field">
                    <label for="wsi-filter-programme">Programme</label>
                    <select class="wsi-select" name="programme" id="wsi-filter-programme" data-dim="programme"></select>
                </div>
                <div class="wsi-field">
                    <label for="wsi-filter-key">Locker key status</label>
                    <select class="wsi-select" name="key" id="wsi-filter-key" data-dim="key"></select>
                </div>
                <button type="button" class="wsi-link wsi-reset-link" id="wsi-reset-filters">Reset filters</button>
            </div>
        </div>

        <p class="wsi-strip-tip" id="wsi-strip-tip">Tip: click any bar to highlight it across all charts &middot; Ctrl+click to select several &middot; Faded = not highlighted</p>

        <div class="wsi-strip" id="wsi-strip" hidden>
            <div class="wsi-chip-strip" id="wsi-chip-strip"></div>
            <div class="wsi-strip-right">
                <button type="button" class="wsi-link" id="wsi-clear-all" hidden>Clear all</button>
                <span class="wsi-summary" id="wsi-summary" aria-live="polite">Showing 315 of 315 seats</span>
            </div>
        </div>

        <div class="wsi-selected-panel" id="wsi-selected-panel" hidden>
            <button type="button" class="wsi-selected-toggle" id="wsi-selected-toggle" aria-expanded="false" aria-controls="wsi-selected-body">
                <span id="wsi-selected-title">Selected seats (0)</span>
                <span aria-hidden="true">&#9662;</span>
            </button>
            <div class="wsi-selected-body" id="wsi-selected-body" hidden>
                <div id="wsi-selected-table-wrap"></div>
                <p class="wsi-footer-link"><a class="wsi-link" id="wsi-selected-landing-link" href="#">View on Workstation page &rarr;</a></p>
            </div>
        </div>

        <div class="wsi-grid">
            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">How are the workstations doing right now?</h2>
                <p class="wsi-layer-desc">The five numbers CGS checks first, and how occupancy breaks down by block.</p>
            </div>

            <div class="wsi-span-12">
                <div class="wsi-kpi-row" id="wsi-kpi-row"></div>
                <p class="wsi-kpi-key">Left border: red = urgent &middot; orange = upcoming &middot; gold = needs attention &middot; blue = information</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-status-by-block">
                <h3 class="wsi-card-title">How many seats are in each state?</h3>
                <p class="wsi-card-subtitle">Seats by status for each block.</p>
                <div class="wsi-card-body" id="wsi-status-by-block"></div>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">When will seats become free?</h2>
                <p class="wsi-layer-desc">Projected releases from candidacy expiry and termination notices.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-forecast">
                <div class="wsi-card-head-row">
                    <div>
                        <h3 class="wsi-card-title">Seat release forecast</h3>
                        <p class="wsi-card-subtitle" id="wsi-forecast-subtitle">Month by month.</p>
                    </div>
                    <div class="wsi-segctl" id="wsi-grain-control" role="group" aria-label="Time grain">
                        <button type="button" data-grain="year" aria-pressed="false">Year</button>
                        <button type="button" data-grain="quarter" aria-pressed="false">Quarter</button>
                        <button type="button" data-grain="month" aria-pressed="false">Month</button>
                    </div>
                </div>
                <nav class="wsi-breadcrumb" id="wsi-forecast-breadcrumb" hidden></nav>
                <div class="wsi-card-body" id="wsi-forecast"></div>
                <p class="wsi-footer-note">Maximum possible: an appeal can extend candidacy by up to 12 months. Seats Awaiting Key Return are not included.</p>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Where are seats filling up?</h2>
                <p class="wsi-layer-desc">Occupancy by room and by the gender each room is assigned to.</p>
            </div>

            <div class="wsi-span-7 wsi-card" id="wsi-card-rooms">
                <h3 class="wsi-card-title">Which rooms are filling up?</h3>
                <p class="wsi-card-subtitle">Occupied seats out of capacity, grouped by block, highest first.</p>
                <div class="wsi-card-body" id="wsi-rooms"></div>
            </div>

            <div class="wsi-span-5 wsi-card" id="wsi-card-gender">
                <h3 class="wsi-card-title">Male rooms versus female rooms</h3>
                <p class="wsi-card-subtitle">Status of each group's own rooms.</p>
                <div class="wsi-card-body" id="wsi-gender"></div>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Who is using the workstations?</h2>
                <p class="wsi-layer-desc">Seat holders by programme, candidacy timeline and length of stay.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-programmes">
                <h3 class="wsi-card-title">Programme breakdown</h3>
                <p class="wsi-card-subtitle">Share of seat holders by programme.</p>
                <div class="wsi-card-body" id="wsi-programmes"></div>
            </div>

            <div class="wsi-span-6 wsi-card" id="wsi-card-candidacy">
                <h3 class="wsi-card-title">Candidacy expiry of seat holders</h3>
                <p class="wsi-card-subtitle">How soon each seat holder's candidacy expires.</p>
                <div class="wsi-card-body" id="wsi-candidacy"></div>
            </div>

            <div class="wsi-span-6 wsi-card" id="wsi-card-stay">
                <h3 class="wsi-card-title">Length of stay</h3>
                <p class="wsi-card-subtitle">Time since allocation, for current seat holders.</p>
                <div class="wsi-card-body" id="wsi-stay"></div>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Locker keys</h2>
                <p class="wsi-layer-desc">Where locker keys stand today, and what is coming up for return.</p>
            </div>

            <div class="wsi-span-7 wsi-card" id="wsi-card-keys">
                <h3 class="wsi-card-title">Locker key status of seat holders</h3>
                <p class="wsi-card-subtitle">Current seat holders only.</p>
                <div class="wsi-card-body" id="wsi-keys"></div>
            </div>

            <div class="wsi-span-5 wsi-card" id="wsi-card-returns">
                <h3 class="wsi-card-title">Key returns coming up</h3>
                <p class="wsi-card-subtitle">Locker keys currently Return Pending.</p>
                <div class="wsi-card-body" id="wsi-returns"></div>
            </div>
        </div>
    </div>

    <script type="application/json" id="ins-data">@json($rows)</script>
    <script type="application/json" id="ins-meta">@json($meta)</script>
    <script src="{{ asset('js/chloe-workstation-live.js') }}"></script>
    <script src="{{ asset('js/chloe-insights-aggregate.js') }}"></script>
    <script src="{{ asset('js/chloe-workstation-insights.js') }}"></script>

    <script @cspNonce>
        document.querySelectorAll('[data-browser-back]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (window.history.length > 1 && document.referrer) {
                    event.preventDefault();
                    window.history.back();
                }
            });
        });
    </script>
@endsection
