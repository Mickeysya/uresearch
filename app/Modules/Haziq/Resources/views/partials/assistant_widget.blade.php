{{--
    The student-facing chatbot -- confirmed explicitly 2026-11-06.
    Student-only: checks auth()->user()->isStudent() itself, so it is
    safe to @include on any page without the caller needing to gate it,
    including pages this module shares with staff (allowance, stage
    gate). Independent of Chloe's assistant widget and API key -- see
    Assistant\GeminiClient's own docblock for why.

    A full-figure mascot, not a line icon -- confirmed explicitly
    2026-11-08 after a first pass felt flat: "nak actual robot full
    figure nampak cantik 3d animation model". It's still plain inline
    SVG + CSS (gradients for the shading, perspective/rotateX/Y for the
    3D turn, a floating idle bob, a blinking eye and a waving arm) --
    no WebGL/three.js and no CDN script, since the CSP here is
    nonce-only script-src with no host allow-list and this never needed
    one. The button lost its visible label when the mascot got big
    enough to read as the invitation on its own; aria-label carries it
    for anyone who can't see the comic bubble do the same job.
--}}
@if (auth()->check() && auth()->user()->isStudent())
    <style>
        @keyframes haziq-bot-bob {
            0%, 100% { transform: perspective(500px) rotateY(0deg) translateY(0px); }
            50%      { transform: perspective(500px) rotateY(0deg) translateY(-5px); }
        }
        @keyframes haziq-bot-turn {
            0%, 100% { transform: rotateY(0deg); }
            30%      { transform: rotateY(18deg); }
            70%      { transform: rotateY(-18deg); }
        }
        @keyframes haziq-bot-blink {
            0%, 92%, 100% { transform: scaleY(1); }
            96%            { transform: scaleY(0.12); }
        }
        @keyframes haziq-bot-wave {
            0%, 100% { transform: rotate(0deg); }
            25%      { transform: rotate(-22deg); }
            50%      { transform: rotate(0deg); }
            75%      { transform: rotate(-10deg); }
        }
        @keyframes haziq-bot-glow {
            0%, 100% { opacity: 0.55; r: 2.6; }
            50%      { opacity: 1; r: 3.4; }
        }

        .haziq-assistant-fab {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            background: linear-gradient(145deg, var(--surface-raised), var(--surface));
            border: 1px solid var(--border-grey);
            box-shadow: var(--shadow-lg);
            cursor: pointer;
        }
        .haziq-bot-stage {
            width: 48px;
            height: 48px;
            perspective: 500px;
            animation: haziq-bot-bob 3.2s ease-in-out infinite;
        }
        .haziq-bot-figure {
            width: 100%;
            height: 100%;
            transform-style: preserve-3d;
            animation: haziq-bot-turn 6s ease-in-out infinite;
        }
        .haziq-assistant-fab:hover .haziq-bot-figure {
            animation-play-state: paused;
            transition: transform var(--duration-base) var(--ease);
            transform: rotateY(22deg) scale(1.08);
        }
        .haziq-assistant-fab:active .haziq-bot-figure {
            transition: transform var(--duration-fast) var(--ease);
            transform: rotateY(0deg) scale(0.92);
        }
        .haziq-bot-eye {
            transform-box: fill-box;
            transform-origin: center;
            animation: haziq-bot-blink 4.5s ease-in-out infinite;
        }
        .haziq-bot-arm {
            transform-box: fill-box;
            transform-origin: top center;
            animation: haziq-bot-wave 3.2s ease-in-out infinite;
        }
        .haziq-bot-antenna-tip {
            animation: haziq-bot-glow 1.8s ease-in-out infinite;
        }

        .haziq-bubble {
            position: absolute;
            bottom: 74px;
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
            right: 28px;
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
            bottom: 74px;
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
            .haziq-bot-stage, .haziq-bot-figure, .haziq-bot-eye, .haziq-bot-arm, .haziq-bot-antenna-tip {
                animation: none;
            }
        }
    </style>

    <div id="haziq-assistant" style="position: fixed; bottom: 20px; right: 20px; z-index: 40;">
        <div id="haziq-assistant-bubble" class="haziq-bubble" aria-hidden="true">Ask me anything 💬</div>

        <button type="button" id="haziq-assistant-toggle" class="haziq-assistant-fab" aria-expanded="false" aria-controls="haziq-assistant-panel" aria-label="Ask me anything about your GA/GRA application, Stage Gate, or Allowance" title="Ask me anything about your GA/GRA application, Stage Gate, or Allowance">
            <span class="haziq-bot-stage" aria-hidden="true">
                <svg class="haziq-bot-figure" viewBox="0 0 64 64" width="48" height="48">
                    <defs>
                        <linearGradient id="haziq-bot-head-grad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="var(--navy-400)"/>
                            <stop offset="100%" stop-color="var(--navy-600)"/>
                        </linearGradient>
                        <linearGradient id="haziq-bot-body-grad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--navy-500)"/>
                            <stop offset="100%" stop-color="var(--navy-700)"/>
                        </linearGradient>
                    </defs>

                    {{-- antenna --}}
                    <line x1="32" y1="6" x2="32" y2="14" stroke="var(--navy-600)" stroke-width="2.4" stroke-linecap="round"/>
                    <circle class="haziq-bot-antenna-tip" cx="32" cy="5" r="3" fill="var(--gold)"/>

                    {{-- waving arm, drawn before the body so it reads as behind the shoulder --}}
                    <g class="haziq-bot-arm">
                        <rect x="43" y="28" width="7" height="16" rx="3.5" fill="var(--navy-500)"/>
                        <circle cx="46.5" cy="26" r="3.4" fill="var(--navy-400)"/>
                    </g>
                    <rect x="14" y="30" width="7" height="15" rx="3.5" fill="var(--navy-500)"/>

                    {{-- body, with a little screen/chest panel --}}
                    <rect x="16" y="26" width="32" height="26" rx="10" fill="url(#haziq-bot-body-grad)"/>
                    <rect x="23" y="34" width="18" height="10" rx="4" fill="var(--navy-200)" opacity="0.5"/>
                    <circle cx="27" cy="39" r="1.6" fill="var(--gold)"/>
                    <circle cx="32" cy="39" r="1.6" fill="var(--gold)"/>
                    <circle cx="37" cy="39" r="1.6" fill="var(--gold)"/>

                    {{-- head, with two blinking eyes and a friendly smile --}}
                    <rect x="14" y="12" width="36" height="24" rx="12" fill="url(#haziq-bot-head-grad)"/>
                    <ellipse class="haziq-bot-eye" cx="25" cy="23" rx="3.4" ry="4.2" fill="var(--text-on-accent)"/>
                    <ellipse class="haziq-bot-eye" cx="39" cy="23" rx="3.4" ry="4.2" fill="var(--text-on-accent)"/>
                    <path d="M25 30 Q32 35 39 30" stroke="var(--text-on-accent)" stroke-width="2" stroke-linecap="round" fill="none"/>
                </svg>
            </span>
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
                Ask me anything about GA/GRA, Stage Gate, or Allowance
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

                setTimeout(showBubble, 2000);
                bubbleTimer = setInterval(showBubble, 22000);

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
