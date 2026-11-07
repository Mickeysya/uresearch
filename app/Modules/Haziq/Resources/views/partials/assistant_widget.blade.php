{{--
    The student-facing chatbot -- confirmed explicitly 2026-11-06.
    Student-only: checks auth()->user()->isStudent() itself, so it is
    safe to @include on any page without the caller needing to gate it,
    including pages this module shares with staff (allowance, stage
    gate). Independent of Chloe's assistant widget and API key -- see
    Assistant\GeminiClient's own docblock for why.

    The floating icon tumbles in real CSS 3D (perspective + rotateX/Y,
    transform-style: preserve-3d) rather than a flat 2D wobble, and a
    comic-style speech bubble periodically invites a click -- confirmed
    explicitly 2026-11-08: "icon 3d movement... interesting ak selalu
    keluar cam text die bercakap cam komik". No library and no CDN
    script -- the strict CSP here is nonce-only script-src with no host
    allow-list, and this is plain CSS/inline SVG, nothing it would block.
--}}
@if (auth()->check() && auth()->user()->isStudent())
    <style>
        @keyframes haziq-bot-tumble {
            0%, 100% { transform: perspective(500px) rotateY(0deg) rotateX(0deg) translateY(0px); }
            25%      { transform: perspective(500px) rotateY(16deg) rotateX(6deg) translateY(-3px); }
            50%      { transform: perspective(500px) rotateY(0deg) rotateX(-6deg) translateY(0px); }
            75%      { transform: perspective(500px) rotateY(-16deg) rotateX(6deg) translateY(-3px); }
        }

        .haziq-assistant-fab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: var(--radius-full);
            padding: 10px 18px;
        }
        .haziq-assistant-icon {
            display: inline-flex;
            width: 22px;
            height: 22px;
            align-items: center;
            justify-content: center;
            transform-style: preserve-3d;
            animation: haziq-bot-tumble 4.5s ease-in-out infinite;
        }
        .haziq-assistant-fab:hover .haziq-assistant-icon {
            animation-play-state: paused;
            transition: transform var(--duration-base) var(--ease);
            transform: perspective(500px) rotateY(22deg) scale(1.15);
        }
        .haziq-assistant-fab:active .haziq-assistant-icon {
            transition: transform var(--duration-fast) var(--ease);
            transform: perspective(500px) rotateY(0deg) scale(0.88);
        }

        .haziq-bubble {
            position: absolute;
            bottom: 66px;
            right: 4px;
            background: var(--surface);
            color: var(--text-dark);
            border: 1px solid var(--border-grey);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 8px 14px;
            font-size: var(--text-sm);
            font-weight: 600;
            white-space: nowrap;
            opacity: 0;
            transform: translateY(6px) scale(0.9);
            transform-origin: bottom right;
            transition: opacity var(--duration-base) var(--ease), transform var(--duration-base) var(--ease);
            pointer-events: none;
        }
        .haziq-bubble::after {
            content: '';
            position: absolute;
            bottom: -6px;
            right: 22px;
            width: 12px;
            height: 12px;
            background: var(--surface);
            border-right: 1px solid var(--border-grey);
            border-bottom: 1px solid var(--border-grey);
            transform: rotate(45deg);
        }
        .haziq-bubble.is-visible {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .haziq-assistant-panel {
            display: flex;
            flex-direction: column;
            position: absolute;
            bottom: 54px;
            right: 0;
            width: 320px;
            max-height: 420px;
            opacity: 0;
            transform: scale(0.92) translateY(8px);
            transform-origin: bottom right;
            pointer-events: none;
            transition: opacity var(--duration-base) var(--ease), transform var(--duration-base) var(--ease);
        }
        .haziq-assistant-panel.is-open {
            opacity: 1;
            transform: scale(1) translateY(0);
            pointer-events: auto;
        }

        @media (prefers-reduced-motion: reduce) {
            .haziq-assistant-icon { animation: none; }
        }
    </style>

    <div id="haziq-assistant" style="position: fixed; bottom: 20px; right: 20px; z-index: 40;">
        <div id="haziq-assistant-bubble" class="haziq-bubble" aria-hidden="true">Ask me anything 💬</div>

        <button type="button" id="haziq-assistant-toggle" class="btn haziq-assistant-fab" aria-expanded="false" aria-controls="haziq-assistant-panel">
            <span class="haziq-assistant-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="4" y="8" width="16" height="12" rx="4"/>
                    <path d="M12 8V4"/>
                    <circle cx="12" cy="3" r="1.2" fill="currentColor" stroke="none"/>
                    <circle cx="9" cy="14" r="1.4" fill="currentColor" stroke="none"/>
                    <circle cx="15" cy="14" r="1.4" fill="currentColor" stroke="none"/>
                    <path d="M9 18h6"/>
                    <path d="M2 13h2"/>
                    <path d="M20 13h2"/>
                </svg>
            </span>
            Ask me anything
        </button>

        <div id="haziq-assistant-panel" class="card haziq-assistant-panel">
            <div style="padding: 10px 14px; border-bottom: 1px solid var(--border-grey); font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <span aria-hidden="true" style="display: inline-flex; width: 18px; height: 18px; color: var(--navy);">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="8" width="16" height="12" rx="4"/>
                        <path d="M12 8V4"/>
                        <circle cx="12" cy="3" r="1.2" fill="currentColor" stroke="none"/>
                        <circle cx="9" cy="14" r="1.4" fill="currentColor" stroke="none"/>
                        <circle cx="15" cy="14" r="1.4" fill="currentColor" stroke="none"/>
                        <path d="M9 18h6"/>
                    </svg>
                </span>
                Ask me anything about your GRA/GA
            </div>

            <div id="haziq-assistant-messages" style="flex: 1; overflow-y: auto; padding: 10px 14px; font-size: 13.5px;"></div>

            <form id="haziq-assistant-form" style="display: flex; gap: 6px; padding: 10px 14px; border-top: 1px solid var(--border-grey);">
                <input type="text" id="haziq-assistant-input" placeholder="e.g. when is my Stage 1 deadline?" maxlength="500" style="flex: 1;" required>
                <button type="submit" class="btn-secondary">Ask</button>
            </form>

            <p class="queue-meta" style="padding: 0 14px 10px; margin: 0;">
                Answers come from your own records and may be wrong. Confirm anything important with CGS.
            </p>
        </div>
    </div>

    @push('scripts')
        <script @cspNonce>
            (function () {
                var toggle = document.getElementById('haziq-assistant-toggle');
                var panel = document.getElementById('haziq-assistant-panel');
                var bubble = document.getElementById('haziq-assistant-bubble');
                var messages = document.getElementById('haziq-assistant-messages');
                var form = document.getElementById('haziq-assistant-form');
                var input = document.getElementById('haziq-assistant-input');
                if (! toggle || ! panel || ! form || ! input) return;

                var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                var askUrl = @json(route('haziq-assistant.ask'));

                /* ---- the invite bubble --------------------------------
                   Pops up a few seconds after the page loads, then again
                   every so often, to read as "alive" rather than a static
                   icon -- stops for good the first time the student
                   actually opens the panel, so it never nags someone who
                   already knows it's there. */
                var bubbleTimer = null;

                function showBubble() {
                    if (! bubble || panel.classList.contains('is-open')) return;
                    bubble.classList.add('is-visible');
                    setTimeout(function () { bubble.classList.remove('is-visible'); }, 4000);
                }

                function stopBubbleCycle() {
                    if (bubbleTimer) { clearInterval(bubbleTimer); bubbleTimer = null; }
                    if (bubble) bubble.classList.remove('is-visible');
                }

                setTimeout(showBubble, 2500);
                bubbleTimer = setInterval(showBubble, 25000);

                toggle.addEventListener('click', function () {
                    var opening = ! panel.classList.contains('is-open');
                    panel.classList.toggle('is-open', opening);
                    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
                    if (opening) {
                        stopBubbleCycle();
                        input.focus();
                    }
                });

                function addMessage(text, who) {
                    var row = document.createElement('p');
                    row.style.margin = '0 0 8px';
                    row.style.textAlign = who === 'me' ? 'right' : 'left';
                    var bubbleEl = document.createElement('span');
                    bubbleEl.style.display = 'inline-block';
                    bubbleEl.style.padding = '6px 10px';
                    bubbleEl.style.borderRadius = '10px';
                    bubbleEl.style.background = who === 'me' ? 'var(--accent-solid)' : 'var(--surface-sunken)';
                    bubbleEl.style.color = who === 'me' ? 'var(--text-on-accent)' : 'var(--text-dark)';
                    bubbleEl.textContent = text;
                    row.appendChild(bubbleEl);
                    messages.appendChild(row);
                    messages.scrollTop = messages.scrollHeight;
                }

                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var question = input.value.trim();
                    if (! question) return;

                    addMessage(question, 'me');
                    input.value = '';
                    input.disabled = true;

                    fetch(askUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({question: question}),
                    })
                        .then(function (response) { return response.json().then(function (data) { return {status: response.status, data: data}; }); })
                        .then(function (result) {
                            addMessage(result.data.answer || "Sorry, something went wrong.", 'bot');
                        })
                        .catch(function () {
                            addMessage("Sorry, I couldn't reach the assistant service right now.", 'bot');
                        })
                        .finally(function () {
                            input.disabled = false;
                            input.focus();
                        });
                });
            })();
        </script>
    @endpush
@endif
