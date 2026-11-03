{{--
    PART 2: the Workstation Assistant floating button and chat panel.
    Self-guarded on auth + role so it can be @include'd into any Workstation
    view without that view needing to know or check who is logged in --
    renders nothing at all for a guest, an approver, or any other role.
    Only this module's own files are touched: no shared layout edit, no
    shared stylesheet, no shared script. CSS/JS are namespaced "wsa-" and
    live only here.
--}}
@php
    // $wsaDomain: 'workstation' (default) or 'candidacy' -- the page only gives the assistant a PRIOR (that domain's entries first and its own suggested questions);
    // it is the same assistant, endpoint and rules either way. $wsaStatic is the old "coming soon" mode and is no longer used by any page.
    $wsaStatic = $wsaStatic ?? null;
    $wsaPage = request()->route()?->getName();
    // the page's domain comes from config('assistant.domain_pages'); the include's own $wsaDomain is only used for a page that is not mapped (and then the server will refuse it)
    $wsaDomain = \App\Modules\Chloe\Assistant\AssistantDomains::forPage($wsaPage) ?? (($wsaDomain ?? 'workstation') === 'candidacy' ? 'candidacy' : 'workstation');
    $wsaName = \App\Modules\Chloe\Assistant\AssistantDomains::title($wsaDomain);
    $wsaRole = auth()->check() ? ($wsaStatic ? 'static' : \App\Modules\Chloe\Assistant\AssistantDomains::effectiveRole(auth()->user(), $wsaDomain)) : null;
    $wsaToken = auth()->check() && $wsaPage ? \App\Modules\Chloe\Assistant\AssistantDomains::token($wsaDomain, auth()->id(), $wsaPage) : '';
@endphp
@if ($wsaRole !== null)
    @php
        $wsaIsCgs = in_array($wsaRole, ['cgs', 'cgs_approver'], true);

        if ($wsaDomain === 'candidacy') {
            [$wsaFirstMessage, $wsaHint, $wsaGreeting] = match (true) {
                $wsaIsCgs => ["Hi! I can answer questions about appeals, expiring candidacies, reminders and the dismissal list. I can't make changes, but I can link you to the right page.", "Ask about counts and steps. Names aren't supported, and I can't approve, export or dismiss.", 'Hi! Ask me about appeals, reminders or the rules.'],
                $wsaRole === 'approver' => ["Hi! I can tell you how many appeals wait for you, explain your step and the rules. I can't approve or return an appeal for you.", "Guidance only. I can't make changes.", 'Hi! Ask me about appeals, reminders or the rules.'],
                default => ["Hi! I can tell you when your candidacy expires, how many extension months you have left, where your appeal is and how appeals work. Choose a question or type your own.", "Guidance only. I can't make changes or predict a decision.", 'Hi! Questions about your candidacy or appeal?'],
            };
        } else {
            $wsaFirstMessage = $wsaIsCgs
                ? "Hi! I can answer questions about seats, locker keys and workstation rules. I can't make changes, but I can link you to the right page."
                : 'Hi! I can help with workstations, locker keys and the Term of Use. Choose a question or type your own.';
            $wsaHint = $wsaIsCgs
                ? "Search by Student ID or seat number. Names aren't supported."
                : "Guidance only. I can't make changes.";
            $wsaGreeting = $wsaIsCgs
                ? 'Hi! Ask me about seats, keys or the rules.'
                : 'Hi! Questions about seats or your locker key?';
        }

        if ($wsaStatic) {
            $wsaFirstMessage = $wsaStatic;
            $wsaHint = 'Coming soon.';
            $wsaGreeting = "Hi! I'm coming soon.";
        }
    @endphp
    <div
        id="wsa-root"
        data-role="{{ $wsaRole }}"
        data-domain="{{ $wsaDomain }}"
        data-page="{{ $wsaPage }}"
        data-token="{{ $wsaToken }}"
        data-placeholder="{{ \App\Modules\Chloe\Assistant\AssistantDomains::placeholder($wsaDomain) }}"
        data-csrf="{{ csrf_token() }}"
        data-char-limit="{{ (int) config('assistant.max_question_length', 300) }}"
        data-recent-turns="{{ (int) config('assistant.recent_turns', 5) }}"
        @if ($wsaStatic) data-static="1" @else
        data-suggestions-url="{{ route('workstation.assistant.suggestions') }}"
        data-suggestion-url="{{ route('workstation.assistant.suggestion') }}"
        data-ask-url="{{ route('workstation.assistant.ask') }}"
        @endif
        data-first-message="{{ $wsaFirstMessage }}"
        data-greeting="{{ $wsaGreeting }}"
    >
        {{--
            PART D.1: an ORIGINAL inline SVG robot head -- never a stock
            icon. One <template> holding the shared markup, cloned by JS
            wherever the icon appears (the button, the header, and once
            per assistant message's avatar) so there is exactly one
            authored copy of it, never innerHTML of anything dynamic.
            Fills about 80% of the circle it sits in. The visor's colour
            comes from the "wsa-robot-visor" CSS class, never an inline
            style="" attribute (PART E's own rule: no inline style for
            any visual width or colour).
        --}}
        <template id="wsa-robot-template">
            <svg viewBox="0 0 24 24" class="wsa-robot-svg" aria-hidden="true">
                <circle class="wsa-robot-antenna-ball" cx="12" cy="2.6" r="1.4"></circle>
                <line x1="12" y1="4" x2="12" y2="6.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></line>
                <rect class="wsa-robot-ear" x="2.6" y="10.5" width="2" height="4.6" rx="1"></rect>
                <rect class="wsa-robot-ear" x="19.4" y="10.5" width="2" height="4.6" rx="1"></rect>
                <rect x="4.3" y="6.4" width="15.4" height="14.2" rx="4.4" fill="currentColor"></rect>
                <path d="M3.6 20.6 1.8 23.4l4.6-1.9Z" fill="currentColor"></path>
                <rect class="wsa-robot-visor" x="6.7" y="10.2" width="10.6" height="5.6" rx="2.4"></rect>
                <circle class="wsa-robot-pupil" cx="9.6" cy="13" r="1.05"></circle>
                <circle class="wsa-robot-pupil" cx="14.4" cy="13" r="1.05"></circle>
            </svg>
        </template>

        <button type="button" id="wsa-toggle" class="wsa-toggle" aria-haspopup="dialog" aria-controls="wsa-panel" aria-expanded="false" aria-label="Open {{ $wsaName }}" title="{{ $wsaName }}">
            <span id="wsa-toggle-icon" class="wsa-toggle-icon" data-mood="idle"></span>
        </button>

        {{--
            PART D.2: the first-visit greeting -- a small speech bubble
            above the button, shown once per browser session (the single
            boolean flag it reads/writes is sessionStorage, never
            conversation content). Static markup here; JS only toggles
            [hidden] and fills in the role-specific line via textContent.
        --}}
        <div id="wsa-greeting" class="wsa-greeting" role="status" hidden>
            <button type="button" id="wsa-greeting-close" class="wsa-greeting-close" aria-label="Dismiss">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"></path>
                </svg>
            </button>
            <span id="wsa-greeting-text"></span>
        </div>

        <div id="wsa-panel" class="wsa-panel" role="dialog" aria-modal="false" aria-label="{{ $wsaName }}" aria-hidden="true" hidden>
            <div class="wsa-header">
                <span class="wsa-header-title-wrap">
                    <span id="wsa-header-icon" class="wsa-header-icon" aria-hidden="true"></span>
                    <span class="wsa-title">{{ $wsaName }}</span>
                </span>
                <div class="wsa-header-actions">
                    <button type="button" id="wsa-new-chat" class="wsa-text-btn">New chat</button>
                    <button type="button" id="wsa-close" class="wsa-icon-btn" aria-label="Close {{ $wsaName }}">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <div id="wsa-messages" class="wsa-messages" role="log" aria-live="polite" aria-label="Conversation"></div>

            <form id="wsa-form" class="wsa-footer" autocomplete="off">
                <div class="wsa-input-row">
                    <input type="text" id="wsa-input" class="wsa-input" placeholder="{{ \App\Modules\Chloe\Assistant\AssistantDomains::placeholder($wsaDomain) }}" maxlength="{{ (int) config('assistant.max_question_length', 300) }}" aria-label="Ask a question">
                    <button type="submit" id="wsa-send" class="wsa-send-btn" aria-label="Send">
                        {{-- Round 6 PART 7: an upward arrow, ChatGPT-style, not the old paper plane. --}}
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 19V5"></path>
                            <path d="M5 12l7-7 7 7"></path>
                        </svg>
                    </button>
                </div>
                <div class="wsa-footer-note">
                    <span class="wsa-hint">{{ $wsaHint }}</span>
                    <span class="wsa-hint">AI can make mistakes. Please check important information with CGS.</span>
                </div>
            </form>
        </div>
    </div>

    <style>
        /*
            Every selector below is prefixed with the #wsa-root ancestor,
            purely to WIN specificity against the app's own global element
            rules (e.g. input[type="text"], button, a:focus-visible in
            public/css/*.css) -- those are attribute/pseudo-class selectors
            at (0,0,1,1), which beat a bare class like .wsa-input at
            (0,0,1,0). #wsa-root adds an ID to every rule here, (0,1,0,0),
            which always wins regardless of source order, so this module's
            widget never inherits the host page's button/input chrome
            (wrong border-radius, a hardcoded light input background that
            would break in dark mode, etc.) and never leaks its own styles
            back out, since nothing here is unprefixed.
        */
        #wsa-root, #wsa-root * { box-sizing: border-box; font-family: inherit; margin: 0; }

        #wsa-root .wsa-toggle {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: none;
            background: var(--accent-solid, #284B80);
            color: var(--text-on-accent, #fff);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            z-index: 2147483000;
            animation: wsa-breathe 4s ease-in-out infinite;
            transition: box-shadow 0.15s ease, background-color 0.15s ease;
        }
        #wsa-root .wsa-toggle:hover, #wsa-root .wsa-toggle:focus-visible {
            background: var(--accent-hover, #1F3C68);
            animation: none;
            transform: scale(1.08);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.32);
        }
        #wsa-root .wsa-toggle:focus-visible { outline: 2px solid var(--accent-hover, #1F3C68); outline-offset: 2px; }
        #wsa-root .wsa-toggle.wsa-anim-paused { animation-play-state: paused; }
        #wsa-root .wsa-toggle.wsa-anim-paused:hover, #wsa-root .wsa-toggle.wsa-anim-paused:focus-visible { transform: none; }
        #wsa-root .wsa-toggle:hover .wsa-robot-antenna-ball, #wsa-root .wsa-toggle:focus-visible .wsa-robot-antenna-ball {
            animation: wsa-glow-strong 0.8s ease-in-out infinite;
        }

        #wsa-root .wsa-toggle-icon, #wsa-root .wsa-avatar-icon, #wsa-root .wsa-header-icon { display: flex; }
        #wsa-root .wsa-toggle-icon .wsa-robot-svg { width: 48px; height: 48px; }
        #wsa-root .wsa-avatar-icon .wsa-robot-svg { width: 20px; height: 20px; }
        #wsa-root .wsa-header-icon .wsa-robot-svg { width: 22px; height: 22px; }
        #wsa-root .wsa-robot-svg { color: currentColor; overflow: visible; }
        #wsa-root .wsa-robot-ear { fill: currentColor; opacity: 0.85; }
        #wsa-root .wsa-robot-visor { fill: var(--accent-hover, #1F3C68); }
        #wsa-root .wsa-header-icon .wsa-robot-visor { fill: var(--navy-800, #1A3660); }
        #wsa-root .wsa-robot-pupil { fill: #fff; transform: translate(var(--wsa-eye-x, 0px), var(--wsa-eye-y, 0px)); transition: transform 0.12s ease-out; }
        #wsa-root .wsa-robot-antenna-ball { fill: currentColor; animation: wsa-glow 5s ease-in-out infinite; transform-origin: center; }
        #wsa-root .wsa-toggle.wsa-anim-paused .wsa-robot-antenna-ball,
        #wsa-root .wsa-avatar-icon[data-mood] .wsa-robot-antenna-ball { animation: none; }

        /* Idle: gentle breathing scale, periodic blink, soft antenna glow -- transform/opacity only, as required. */
        @keyframes wsa-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }
        @keyframes wsa-glow { 0%, 80%, 100% { opacity: 1; } 90% { opacity: 0.45; } }
        @keyframes wsa-glow-strong { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes wsa-eye-blink { 0%, 92%, 100% { transform: scaleY(1); } 96% { transform: scaleY(0.15); } }
        #wsa-root [data-mood="idle"] .wsa-robot-pupil { animation: wsa-eye-blink 5s ease-in-out infinite; transform-origin: center; }

        /* Thinking: pupils look left/right; Happy: one blink; Oops: a single head tilt, never a loop. */
        @keyframes wsa-look-around { 0%, 100% { transform: translateX(-1.5px); } 50% { transform: translateX(1.5px); } }
        #wsa-root [data-mood="thinking"] .wsa-robot-pupil { animation: wsa-look-around 1.1s ease-in-out infinite; }
        @keyframes wsa-happy-blink { 0% { transform: scaleY(1); } 40% { transform: scaleY(0.15); } 100% { transform: scaleY(1); } }
        #wsa-root [data-mood="happy"] .wsa-robot-pupil { animation: wsa-happy-blink 0.25s ease-out 1; transform-origin: center; }
        @keyframes wsa-oops-tilt { 0% { transform: rotate(0deg); } 40% { transform: rotate(-6deg); } 100% { transform: rotate(0deg); } }
        #wsa-root [data-mood="oops"] .wsa-robot-svg { animation: wsa-oops-tilt 0.5s ease-in-out 1; transform-origin: 50% 70%; }

        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-toggle,
            #wsa-root .wsa-robot-antenna-ball,
            #wsa-root .wsa-robot-pupil,
            #wsa-root .wsa-robot-svg,
            #wsa-root [data-mood] .wsa-robot-pupil { animation: none !important; transition: none !important; transform: none !important; }
            #wsa-root .wsa-toggle:hover, #wsa-root .wsa-toggle:focus-visible { transform: none; }
        }

        #wsa-root .wsa-greeting {
            position: fixed;
            right: 24px;
            bottom: 90px;
            z-index: 2147483000;
            max-width: 230px;
            background: var(--surface-raised, #fff);
            color: var(--text-dark, #222);
            border: 1px solid var(--border-grey, #C6D5EE);
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            padding: 10px 26px 10px 12px;
            font-size: 13px;
            line-height: 1.35;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        #wsa-root .wsa-greeting.wsa-greeting-visible { opacity: 1; transform: translateY(0); }
        #wsa-root .wsa-greeting[hidden] { display: none; }
        #wsa-root .wsa-greeting-close {
            position: absolute;
            top: 6px;
            right: 6px;
            background: none;
            border: none;
            color: var(--text-grey, #888);
            cursor: pointer;
            display: flex;
            padding: 2px;
        }
        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-greeting { transition: none; }
        }

        #wsa-root .wsa-avatar {
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        #wsa-root .wsa-avatar-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--accent-solid, #284B80);
            color: var(--text-on-accent, #fff);
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }

        #wsa-root .wsa-panel {
            position: fixed;
            right: 24px;
            bottom: 96px;
            width: 400px;
            height: 520px;
            max-height: 80vh;
            background: var(--surface-raised, #fff);
            border: 1px solid var(--border-grey, #C6D5EE);
            border-radius: 14px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            z-index: 2147483000;
            font-size: 14px;
            color: var(--text-body, #333);
            transform-origin: bottom right;
            opacity: 0;
            transform: scale(0.92);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        #wsa-root .wsa-panel.wsa-panel-visible { opacity: 1; transform: scale(1); }
        #wsa-root .wsa-panel[hidden] { display: none; }
        #wsa-root .wsa-panel:not(.wsa-panel-visible):not([hidden]) { transition-duration: 0.12s; }
        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-panel { transition: opacity 0.1s linear; transform: none !important; }
        }

        @media (max-width: 520px) {
            #wsa-root .wsa-panel {
                left: 8px;
                right: 8px;
                width: auto;
                bottom: 88px;
            }
        }

        #wsa-root .wsa-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: var(--accent-solid, #284B80);
            color: var(--text-on-accent, #fff);
            flex-shrink: 0;
        }
        #wsa-root .wsa-header-title-wrap { display: flex; align-items: center; gap: 8px; }
        #wsa-root .wsa-title { font-weight: 600; font-size: 15px; }
        #wsa-root .wsa-header-actions { display: flex; align-items: center; gap: 10px; }

        #wsa-root .wsa-text-btn {
            background: none;
            border: none;
            color: var(--text-on-accent, #fff);
            text-decoration: underline;
            font-size: 13px;
            cursor: pointer;
            padding: 2px 4px;
        }
        #wsa-root .wsa-icon-btn {
            background: none;
            border: none;
            color: var(--text-on-accent, #fff);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 2px;
            border-radius: 4px;
        }
        #wsa-root .wsa-icon-btn:hover, #wsa-root .wsa-icon-btn:focus-visible,
        #wsa-root .wsa-text-btn:hover, #wsa-root .wsa-text-btn:focus-visible {
            background: rgba(255, 255, 255, 0.18);
            outline: none;
        }

        #wsa-root .wsa-messages {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: var(--surface-sunken, #F8F9FB);
        }

        #wsa-root .wsa-row { display: flex; }
        #wsa-root .wsa-row.wsa-row-user { justify-content: flex-end; }
        #wsa-root .wsa-row.wsa-row-assistant { justify-content: flex-start; }

        #wsa-root .wsa-bubble {
            max-width: 85%;
            padding: 8px 12px;
            border-radius: 12px;
            word-break: break-word;
            line-height: 1.4;
            overflow-wrap: anywhere;
            animation: wsa-bubble-in 0.15s ease-out;
        }
        /* Answer arrival: fade + slide up 8px over 150ms. */
        @keyframes wsa-bubble-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-bubble { animation: none; }
        }
        #wsa-root .wsa-paragraph { margin: 0 0 6px; }
        #wsa-root .wsa-paragraph:last-child { margin-bottom: 0; }
        #wsa-root .wsa-list { margin: 2px 0 6px 18px; padding: 0; }
        #wsa-root .wsa-list:last-child { margin-bottom: 0; }
        #wsa-root .wsa-list li { margin-bottom: 3px; }
        #wsa-root .wsa-bubble-user { background: var(--accent-solid, #284B80); color: var(--text-on-accent, #fff); }
        #wsa-root .wsa-bubble-assistant { background: var(--surface-raised, #fff); color: var(--text-dark, #222); border: 1px solid var(--border-grey, #C6D5EE); }
        #wsa-root .wsa-bubble-refused { background: var(--surface-sunken, #F8F9FB); color: var(--text-grey, #666); border: 1px dashed var(--border-strong, #9DB4DC); }
        #wsa-root .wsa-bubble-error { background: var(--surface-raised, #fff); color: var(--text-grey, #666); border: 1px solid var(--border-grey, #C6D5EE); font-style: italic; }

        #wsa-root .wsa-interpreted {
            font-size: 12px;
            color: var(--text-grey, #888);
            margin-top: 4px;
        }

        #wsa-root .wsa-links { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        #wsa-root .wsa-link-chip {
            display: inline-block;
            font-size: 12px;
            color: var(--accent-solid, #284B80);
            text-decoration: underline;
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px 0;
        }

        #wsa-root .wsa-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        #wsa-root .wsa-chips-label { flex-basis: 100%; font-size: 12px; color: var(--text-grey, #888); }
        #wsa-root .wsa-chip {
            background: var(--accent-subtle, #E2EAFA);
            color: var(--accent-solid, #284B80);
            border: 1px solid var(--border-grey, #C6D5EE);
            border-radius: 16px;
            padding: 6px 12px;
            font-size: 13px;
            cursor: pointer;
            text-align: left;
        }
        #wsa-root .wsa-chip:hover, #wsa-root .wsa-chip:focus-visible { background: var(--accent-hover, #1F3C68); color: var(--text-on-accent, #fff); }

        /* Chips appear one after another, ~40ms apart. */
        #wsa-root .wsa-chip { opacity: 0; animation: wsa-chip-in 0.18s ease-out forwards; }
        @keyframes wsa-chip-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        #wsa-root .wsa-chip:nth-child(1) { animation-delay: 0ms; }
        #wsa-root .wsa-chip:nth-child(2) { animation-delay: 40ms; }
        #wsa-root .wsa-chip:nth-child(3) { animation-delay: 80ms; }
        #wsa-root .wsa-chip:nth-child(4) { animation-delay: 120ms; }
        #wsa-root .wsa-chip:nth-child(5) { animation-delay: 160ms; }
        #wsa-root .wsa-chip:nth-child(6) { animation-delay: 200ms; }
        #wsa-root .wsa-chip:nth-child(n+7) { animation-delay: 240ms; }
        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-chip { animation: none; opacity: 1; }
        }

        #wsa-root .wsa-typing { display: inline-flex; gap: 4px; padding: 10px 12px; }
        #wsa-root .wsa-typing span {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--text-grey, #999);
            animation: wsa-bounce 1.2s infinite ease-in-out;
        }
        #wsa-root .wsa-typing span:nth-child(2) { animation-delay: 0.15s; }
        #wsa-root .wsa-typing span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes wsa-bounce { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; } 40% { transform: scale(1); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) {
            #wsa-root .wsa-typing span { animation: none; opacity: 1; }
        }

        #wsa-root .wsa-footer {
            flex-shrink: 0;
            border-top: 1px solid var(--border-grey, #C6D5EE);
            padding: 10px 12px;
            background: var(--surface-raised, #fff);
        }
        #wsa-root .wsa-input-row { display: flex; gap: 8px; }
        #wsa-root .wsa-input {
            flex: 1 1 auto;
            border: 1px solid var(--border-grey, #C6D5EE);
            border-radius: 20px;
            padding: 8px 14px;
            font-size: 14px;
            color: var(--text-dark, #222);
            background: var(--surface, #fff);
            width: auto;
        }
        #wsa-root .wsa-input:focus-visible { outline: 2px solid var(--accent-solid, #284B80); outline-offset: 1px; }
        #wsa-root .wsa-input::placeholder { color: var(--text-grey, #888); }

        /*
            Round 6 PART 7: a fixed 36px solid circle in the UTP corporate
            blue (#004B85, not the shared --accent-solid token) with a
            white upward-arrow icon -- enabled is blue, disabled is a
            genuine grey circle (not just a dimmed blue one), so the two
            states read as distinct at a glance.
        */
        #wsa-root .wsa-send-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: #004B85;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color 0.15s ease;
        }
        #wsa-root .wsa-send-btn:disabled { background: #AEB4BC; color: #fff; opacity: 1; cursor: not-allowed; }
        #wsa-root .wsa-send-btn:hover:not(:disabled), #wsa-root .wsa-send-btn:focus-visible { background: #00325C; }

        #wsa-root .wsa-footer-note {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 6px;
        }
        #wsa-root .wsa-hint { font-size: 11px; color: var(--text-grey, #888); }
    </style>

    <script @cspNonce>
        (function () {
            var root = document.getElementById('wsa-root');
            if (! root) return;

            var toggle = document.getElementById('wsa-toggle');
            var panel = document.getElementById('wsa-panel');
            var closeBtn = document.getElementById('wsa-close');
            var newChatBtn = document.getElementById('wsa-new-chat');
            var messages = document.getElementById('wsa-messages');
            var form = document.getElementById('wsa-form');
            var input = document.getElementById('wsa-input');
            var sendBtn = document.getElementById('wsa-send');
            var robotTemplate = document.getElementById('wsa-robot-template');
            var toggleIconSlot = document.getElementById('wsa-toggle-icon');
            var headerIconSlot = document.getElementById('wsa-header-icon');
            var greeting = document.getElementById('wsa-greeting');
            var greetingClose = document.getElementById('wsa-greeting-close');
            var greetingText = document.getElementById('wsa-greeting-text');

            function cloneRobotIcon() {
                return robotTemplate && robotTemplate.content ? robotTemplate.content.cloneNode(true) : document.createDocumentFragment();
            }

            if (toggleIconSlot) toggleIconSlot.appendChild(cloneRobotIcon());
            if (headerIconSlot) headerIconSlot.appendChild(cloneRobotIcon());

            var charLimit = parseInt(root.getAttribute('data-char-limit'), 10) || 300;
            var recentTurns = parseInt(root.getAttribute('data-recent-turns'), 10) || 5;
            var csrfToken = root.getAttribute('data-csrf');
            var suggestionsUrl = root.getAttribute('data-suggestions-url');
            var suggestionUrl = root.getAttribute('data-suggestion-url');
            var askUrl = root.getAttribute('data-ask-url');
            var greetingLine = root.getAttribute('data-greeting') || '';
            var firstMessage = root.getAttribute('data-first-message');
            // Static (coming soon) mode: one fixed line, nothing to ask, and no request is ever made.
            var isStatic = root.getAttribute('data-static') === '1';
            var wsaDomain = root.getAttribute('data-domain') || 'workstation';
            var wsaPage = root.getAttribute('data-page') || '', wsaToken = root.getAttribute('data-token') || '';
            var wsaDomainQuery = 'domain=' + encodeURIComponent(wsaDomain) + '&page=' + encodeURIComponent(wsaPage) + '&token=' + encodeURIComponent(wsaToken);
            var wsaClaim = { domain: wsaDomain, page: wsaPage, token: wsaToken };
            if (isStatic) { input.disabled = true; input.placeholder = 'Coming soon'; sendBtn.disabled = true; }

            // PART 2 #8: conversation lives only in this closure's memory --
            // never localStorage/sessionStorage/a cookie/the server. A
            // reload, logout, or "New chat" all start empty.
            var recentUserQuestions = [];
            var suggestionsCache = null;
            var hasOpenedBefore = false;
            var pendingController = null;

            function createMessageRow(role) {
                var row = document.createElement('div');
                row.className = 'wsa-row ' + (role === 'user' ? 'wsa-row-user' : 'wsa-row-assistant');
                return row;
            }

            function addUserMessage(text) {
                var row = createMessageRow('user');
                var bubble = document.createElement('div');
                bubble.className = 'wsa-bubble wsa-bubble-user';
                bubble.textContent = text;
                row.appendChild(bubble);
                messages.appendChild(row);
                scrollToBottom();
            }

            /** Builds DOM nodes only, never innerHTML of anything dynamic: a line starting with "• " joins the current bullet list, any other non-blank line becomes its own short paragraph. */
            function renderAnswerBody(bubble, text) {
                var lines = (text || '').split('\n');
                var list = null;

                lines.forEach(function (line) {
                    var trimmed = line.trim();

                    if (trimmed === '') {
                        list = null;

                        return;
                    }

                    if (trimmed.indexOf('• ') === 0 || trimmed.indexOf('•') === 0) {
                        if (! list) {
                            list = document.createElement('ul');
                            list.className = 'wsa-list';
                            bubble.appendChild(list);
                        }

                        var li = document.createElement('li');
                        li.textContent = trimmed.replace(/^•\s*/, '');
                        list.appendChild(li);

                        return;
                    }

                    list = null;
                    var p = document.createElement('p');
                    p.className = 'wsa-paragraph';
                    p.textContent = line;
                    bubble.appendChild(p);
                });
            }

            /** PART D.3: idle|thinking|happy|oops -- the same template, a different data-mood driving the CSS animation (see the keyframes above). */
            function addAvatar(row, mood) {
                var avatarWrap = document.createElement('div');
                avatarWrap.className = 'wsa-avatar-icon';
                avatarWrap.setAttribute('data-mood', mood || 'idle');
                avatarWrap.setAttribute('aria-hidden', 'true');
                avatarWrap.appendChild(cloneRobotIcon());
                row.appendChild(avatarWrap);

                return avatarWrap;
            }

            /** outcome: answered|asked_back|refused|unavailable|error. links: [{label,url}]. interpretedAs: string|null. */
            function addAssistantMessage(text, outcome, links, interpretedAs, notice) {
                var row = createMessageRow('assistant');
                row.className += ' wsa-avatar';
                var isProblem = outcome === 'error' || outcome === 'unavailable';
                var avatar = addAvatar(row, isProblem ? 'oops' : 'happy');

                // PART D's own rule: a single blink/tilt, never a loop -- the
                // mood reverts to idle once its one-shot animation has had
                // time to play (650ms covers both the 500ms oops tilt and
                // the 250ms happy blink with margin).
                setTimeout(function () { avatar.setAttribute('data-mood', 'idle'); }, 650);

                var bubble = document.createElement('div');
                var style = outcome === 'refused' ? 'wsa-bubble-refused' : (outcome === 'error' ? 'wsa-bubble-error' : 'wsa-bubble-assistant');
                bubble.className = 'wsa-bubble ' + style;
                renderAnswerBody(bubble, text || '');
                row.appendChild(bubble);

                if (Array.isArray(links) && links.length > 0) {
                    var linksWrap = document.createElement('div');
                    linksWrap.className = 'wsa-links';
                    links.forEach(function (link) {
                        if (! link || typeof link.url !== 'string' || ! isSafeLink(link.url)) return;
                        var a = document.createElement('a');
                        a.className = 'wsa-link-chip';
                        a.href = link.url;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.textContent = typeof link.label === 'string' && link.label !== '' ? link.label : link.url;
                        linksWrap.appendChild(a);
                    });
                    if (linksWrap.childNodes.length > 0) bubble.appendChild(linksWrap);
                }

                if (typeof interpretedAs === 'string' && interpretedAs !== '') {
                    var note = document.createElement('div');
                    note.className = 'wsa-interpreted';
                    note.textContent = 'Interpreted as: ' + interpretedAs;
                    bubble.appendChild(note);
                }

                // the keyword fallback answered because the model was unavailable: a small grey line, never for model, cache or chip answers
                if (typeof notice === 'string' && notice !== '') {
                    var fallbackNote = document.createElement('div');
                    fallbackNote.className = 'wsa-interpreted wsa-fallback-notice';
                    fallbackNote.textContent = notice;
                    bubble.appendChild(fallbackNote);
                }

                messages.appendChild(row);
                scrollToBottom();
            }

            /** Only a relative path ("/...") or a same-host absolute URL -- never an attacker-controlled off-site redirect. */
            function isSafeLink(url) {
                if (url.indexOf('/') === 0 && url.indexOf('//') !== 0) return true;

                try {
                    var parsed = new URL(url, window.location.href);

                    return parsed.origin === window.location.origin;
                } catch (e) {
                    return false;
                }
            }

            /** label (optional): a small grey caption above the chips, e.g. "Did you mean:". */
            function addChips(questions, onPick, label) {
                var row = createMessageRow('assistant');
                var wrap = document.createElement('div');
                wrap.className = 'wsa-chips';

                if (label) {
                    var caption = document.createElement('div');
                    caption.className = 'wsa-chips-label';
                    caption.textContent = label;
                    wrap.appendChild(caption);
                }

                questions.forEach(function (q) {
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'wsa-chip';
                    chip.textContent = q.text;
                    chip.addEventListener('click', function () { onPick(q); });
                    wrap.appendChild(chip);
                });

                row.appendChild(wrap);
                messages.appendChild(row);
                scrollToBottom();
            }

            function scrollToBottom() {
                messages.scrollTop = messages.scrollHeight;
            }

            function showTyping() {
                var row = createMessageRow('assistant');
                row.id = 'wsa-typing-row';
                row.className += ' wsa-avatar';
                addAvatar(row, 'thinking');
                var typing = document.createElement('div');
                typing.className = 'wsa-typing';
                for (var i = 0; i < 3; i++) {
                    typing.appendChild(document.createElement('span'));
                }
                row.appendChild(typing);
                messages.appendChild(row);
                scrollToBottom();
            }

            function hideTyping() {
                var row = document.getElementById('wsa-typing-row');
                if (row) row.remove();
            }

            // Round 6 PART 7: Send is disabled on EITHER condition -- an
            // empty input, or an in-flight request -- not just the
            // latter (the one setBusy() alone used to cover).
            var wsaBusy = false;
            function updateSendButtonState() {
                sendBtn.disabled = wsaBusy || input.value.trim() === '';
            }
            function setBusy(busy) {
                wsaBusy = busy;
                input.disabled = busy;
                updateSendButtonState();
            }
            input.addEventListener('input', updateSendButtonState);
            updateSendButtonState();

            function fetchSuggestions() {
                if (isStatic) return Promise.resolve([]);
                if (suggestionsCache) return Promise.resolve(suggestionsCache);

                return fetch(suggestionsUrl + (suggestionsUrl.indexOf('?') === -1 ? '?' : '&') + wsaDomainQuery, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                }).then(function (res) {
                    if (! res.ok) throw new Error('bad status');

                    return res.json();
                }).then(function (data) {
                    suggestionsCache = Array.isArray(data.suggestions) ? data.suggestions : [];

                    return suggestionsCache;
                }).catch(function () {
                    return [];
                });
            }

            function showSuggestedChips() {
                fetchSuggestions().then(function (list) {
                    if (list.length === 0) return;
                    addChips(list, pickSuggestion);
                });
            }

            /** Shared by the 12 curated suggestion chips and a "Did you mean" chip -- the latter also carries the router's own params (e.g. a period), the former never does. No model call either way. */
            function pickSuggestion(suggestion) {
                addUserMessage(suggestion.text);
                runRequest(function (signal) {
                    return fetch(suggestionUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: signal,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(Object.assign({ id: suggestion.id, params: suggestion.params || {} }, wsaClaim)),
                    });
                });
            }

            function submitTypedQuestion(text) {
                addUserMessage(text);
                recentUserQuestions.push(text);
                if (recentUserQuestions.length > recentTurns) {
                    recentUserQuestions = recentUserQuestions.slice(-recentTurns);
                }

                runRequest(function (signal) {
                    return fetch(askUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: signal,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(Object.assign({ message: text, recent: recentUserQuestions.slice(0, -1) }, wsaClaim)),
                    });
                });
            }

            /** Shared by both endpoints: typing indicator, Send disabled, a 25s client timeout, and uniform error handling. */
            function runRequest(makeFetch) {
                setBusy(true);
                showTyping();

                if (pendingController) pendingController.abort();
                var controller = new AbortController();
                pendingController = controller;
                var timedOut = false;
                var timer = setTimeout(function () {
                    timedOut = true;
                    controller.abort();
                }, 25000);

                makeFetch(controller.signal)
                    .then(function (res) {
                        if (! res.ok) throw new Error('bad status');

                        return res.json();
                    })
                    .then(function (data) {
                        clearTimeout(timer);
                        hideTyping();
                        setBusy(false);
                        addAssistantMessage(data.answer, data.outcome, data.links, data.interpreted_as, data.notice);

                        if (Array.isArray(data.suggestions) && data.suggestions.length > 0) {
                            addChips(data.suggestions, pickSuggestion);
                        }

                        // Part 2.5: "Did you mean" chips -- below the
                        // confidence threshold, or the router also named
                        // an alternative. Same chip primitive, same
                        // click path (pickSuggestion -> the suggestion
                        // endpoint, no model call) as the 12 curated
                        // suggestions above.
                        if (Array.isArray(data.chips) && data.chips.length > 0) {
                            addChips(data.chips.slice(0, 3), pickSuggestion, 'Did you mean:');
                        }
                    })
                    .catch(function () {
                        clearTimeout(timer);
                        hideTyping();
                        setBusy(false);
                        addAssistantMessage('Something went wrong. Please try again.', 'error', [], null);
                    });
            }

            /** PART D.4: the idle animation (breathing/glow/blink) pauses while the panel is open, and separately while the browser tab is hidden (prefers-reduced-motion is handled in CSS alone, no JS needed for that one). */
            function updateAnimationPause() {
                toggle.classList.toggle('wsa-anim-paused', ! panel.hidden || document.hidden);
            }

            /** PART D.2: the greeting never shows once the panel has been opened in this session. */
            function hideGreeting() {
                if (!greeting || greeting.hidden) return;
                greeting.classList.remove('wsa-greeting-visible');
                greeting.hidden = true;
            }

            function openPanel() {
                hideGreeting();
                panel.hidden = false;
                panel.setAttribute('aria-hidden', 'false');
                toggle.setAttribute('aria-expanded', 'true');
                updateAnimationPause();

                // Remove then re-add on the next frame so the browser
                // actually runs the opacity/scale transition in, rather
                // than starting already-visible.
                panel.classList.remove('wsa-panel-visible');
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () { panel.classList.add('wsa-panel-visible'); });
                });

                if (! hasOpenedBefore) {
                    hasOpenedBefore = true;
                    addAssistantMessage(firstMessage, 'answered', [], null);
                    showSuggestedChips();
                }

                input.focus();
                document.addEventListener('keydown', onKeydown);
            }

            function closePanel() {
                panel.classList.remove('wsa-panel-visible');
                panel.setAttribute('aria-hidden', 'true');
                toggle.setAttribute('aria-expanded', 'false');
                document.removeEventListener('keydown', onKeydown);
                toggle.focus();

                setTimeout(function () {
                    panel.hidden = true;
                    updateAnimationPause();
                }, 130);
            }

            document.addEventListener('visibilitychange', updateAnimationPause);

            function onKeydown(e) {
                if (e.key === 'Escape') closePanel();
            }

            // PART D.2: once per browser SESSION (never conversation data),
            // 4 seconds after load, show the greeting for 6 seconds, then
            // fade out -- skipped entirely if the panel is already open,
            // or if it was already shown this session.
            (function initGreeting() {
                if (! greeting || ! greetingText || greetingLine === '') return;

                var alreadyShown = false;
                try {
                    alreadyShown = sessionStorage.getItem('wsaGreetingShown:' + wsaDomain) === '1';
                } catch (e) {}

                if (alreadyShown) return;

                setTimeout(function () {
                    if (! panel.hidden) return;

                    greetingText.textContent = greetingLine;
                    greeting.hidden = false;
                    requestAnimationFrame(function () { greeting.classList.add('wsa-greeting-visible'); });

                    try {
                        sessionStorage.setItem('wsaGreetingShown:' + wsaDomain, '1');
                    } catch (e) {}

                    setTimeout(hideGreeting, 6000);
                }, 4000);
            })();

            if (greetingClose) greetingClose.addEventListener('click', hideGreeting);

            // PART D.2 (hover eye-tracking): only while the panel is closed
            // and the pointer is within ~240px of the button -- the pupils
            // move at most 2px, set via a CSS custom property from this
            // script, never a raw HTML attribute written into markup.
            (function initEyeTracking() {
                var pupils = toggleIconSlot ? toggleIconSlot.querySelectorAll('.wsa-robot-pupil') : [];
                if (! pupils.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

                var ticking = false;

                function apply(dx, dy) {
                    var max = 2;
                    var len = Math.sqrt(dx * dx + dy * dy) || 1;
                    var x = Math.max(-max, Math.min(max, (dx / len) * Math.min(max, len / 20)));
                    var y = Math.max(-max, Math.min(max, (dy / len) * Math.min(max, len / 20)));

                    pupils.forEach(function (pupil) {
                        pupil.style.setProperty('--wsa-eye-x', x.toFixed(2) + 'px');
                        pupil.style.setProperty('--wsa-eye-y', y.toFixed(2) + 'px');
                    });
                }

                document.addEventListener('mousemove', function (e) {
                    if (! panel.hidden || ticking) return;

                    var rect = toggle.getBoundingClientRect();
                    var cx = rect.left + rect.width / 2;
                    var cy = rect.top + rect.height / 2;
                    var dx = e.clientX - cx;
                    var dy = e.clientY - cy;

                    if (Math.sqrt(dx * dx + dy * dy) > 240) return;

                    ticking = true;
                    requestAnimationFrame(function () {
                        apply(dx, dy);
                        ticking = false;
                    });
                });

                toggle.addEventListener('focus', function () {
                    pupils.forEach(function (pupil) {
                        pupil.style.setProperty('--wsa-eye-x', '0px');
                        pupil.style.setProperty('--wsa-eye-y', '0px');
                    });
                });
            })();

            function resetConversation() {
                recentUserQuestions = [];
                // the conversation lives only in this page (never stored); the one thing kept per domain is the greeting flag, and New chat clears both domains' keys
                try { ['workstation', 'candidacy'].forEach(function (d) { sessionStorage.removeItem('wsaGreetingShown:' + d); }); } catch (e) { /* storage may be blocked */ }
                if (pendingController) pendingController.abort();
                messages.textContent = '';
                hasOpenedBefore = true;
                addAssistantMessage(firstMessage, 'answered', [], null);
                showSuggestedChips();
                input.value = '';
                updateSendButtonState(); // setting .value programmatically fires no 'input' event
            }

            toggle.addEventListener('click', function () {
                if (panel.hidden) {
                    openPanel();
                } else {
                    closePanel();
                }
            });

            closeBtn.addEventListener('click', closePanel);
            newChatBtn.addEventListener('click', resetConversation);

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var text = input.value.trim();
                if (isStatic || text === '' || text.length > charLimit || sendBtn.disabled) return;

                input.value = '';
                updateSendButtonState(); // setting .value programmatically fires no 'input' event
                submitTypedQuestion(text);
            });
        })();
    </script>
@endif
