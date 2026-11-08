{{--
    The student-facing chatbot -- confirmed explicitly 2026-11-06.
    Student-only: checks auth()->user()->isStudent() itself, so it is
    safe to @include on any page without the caller needing to gate it,
    including pages this module shares with staff (allowance, stage
    gate). Independent of Chloe's assistant widget and API key -- see
    Assistant\GeminiClient's own docblock for why.
--}}
@if (auth()->check() && auth()->user()->isStudent())
    <div id="haziq-assistant" style="position: fixed; bottom: 20px; right: 20px; z-index: 40;">
        <button type="button" id="haziq-assistant-toggle" class="btn" style="border-radius: 999px; padding: 10px 18px;">
            Ask about my application
        </button>

        <div id="haziq-assistant-panel" class="card" style="display: none; position: absolute; bottom: 54px; right: 0; width: 320px; max-height: 420px; flex-direction: column;">
            <div style="padding: 10px 14px; border-bottom: 1px solid var(--border, #E4E7EE); font-weight: 600;">
                Ask about your application
            </div>

            <div id="haziq-assistant-messages" style="flex: 1; overflow-y: auto; padding: 10px 14px; font-size: 13.5px;"></div>

            <form id="haziq-assistant-form" style="display: flex; gap: 6px; padding: 10px 14px; border-top: 1px solid var(--border, #E4E7EE);">
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
                var messages = document.getElementById('haziq-assistant-messages');
                var form = document.getElementById('haziq-assistant-form');
                var input = document.getElementById('haziq-assistant-input');
                if (! toggle || ! panel || ! form || ! input) return;

                var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                var askUrl = @json(route('haziq-assistant.ask'));

                toggle.addEventListener('click', function () {
                    var showing = panel.style.display === 'flex';
                    panel.style.display = showing ? 'none' : 'flex';
                });

                function addMessage(text, who) {
                    var row = document.createElement('p');
                    row.style.margin = '0 0 8px';
                    row.style.textAlign = who === 'me' ? 'right' : 'left';
                    var bubble = document.createElement('span');
                    bubble.style.display = 'inline-block';
                    bubble.style.padding = '6px 10px';
                    bubble.style.borderRadius = '10px';
                    bubble.style.background = who === 'me' ? '#5B6E9C' : '#F1F3F7';
                    bubble.style.color = who === 'me' ? '#fff' : '#23283A';
                    bubble.textContent = text;
                    row.appendChild(bubble);
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
