@extends('core::layouts.app')

{{--
    Help and Support (/help). Every word is in config/help.php; HelpController
    picks the sections this viewer gets. Styles: public/css/help.css, all
    scoped under .help.

    Works with JavaScript off: the FAQ is plain <details>/<summary>, and the
    search box, topic tiles and Popular buttons (which do nothing without a
    script) are .help-needs-js and stay hidden until the script below marks
    the page .is-enhanced. The script only filters, and opens a #question
    link -- it never builds content.
--}}

@php
    // Lucide-style stroke icons (24x24 viewBox), drawn inline so the CSP
    // needs nothing extra. Every one is decorative: aria-hidden.
    $icons = [
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12z"/>',
        'file-check' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
        'calendar-check' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    ];
    $icon = fn (string $name, int $size = 20) => '<svg class="help-icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($icons[$name] ?? $icons['compass']).'</svg>';
    $telHref = 'tel:'.preg_replace('/[^0-9+]/', '', (string) ($contact['phone'] ?? ''));
@endphp

@section('content')
<div class="help" id="help-root">

    {{-- ---------- 1. Banner ---------- --}}
    <section class="help-banner" aria-labelledby="help-title">
        <span class="help-ring help-ring--gold" aria-hidden="true"></span>
        <span class="help-ring help-ring--white" aria-hidden="true"></span>

        <div class="help-banner-body">
            <span class="help-eyebrow">Help and Support</span>
            <h1 id="help-title">How can we help you today?</h1>
            <p class="help-banner-sub">
                Search {{ $questionCount }} common questions, or {{ $contactPhrase }}.
            </p>

            <div class="help-search help-needs-js" role="search">
                <label for="help-search" class="help-sr">Search help questions</label>
                {!! $icon('search', 20) !!}
                <input type="search" id="help-search" autocomplete="off" spellcheck="false"
                       aria-controls="help-faq-list"
                       placeholder='Describe your question, e.g. "application returned"'>
            </div>

            @if ($popular !== [])
                <div class="help-popular help-needs-js">
                    <span class="help-popular-label" id="help-popular-label">Popular:</span>
                    <ul class="help-popular-list" aria-labelledby="help-popular-label">
                        @foreach ($popular as $chip)
                            <li><button type="button" class="help-pill" data-term="{{ $chip['term'] }}">{{ $chip['label'] }}</button></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>

    {{-- ---------- 2. Browse by topic ---------- --}}
    <section class="help-topics help-needs-js" aria-labelledby="help-topics-title">
        <div class="help-topics-head">
            <h2 id="help-topics-title">Browse by topic</h2>
            <button type="button" class="help-text-btn" id="help-show-all" hidden>Show all topics</button>
        </div>

        <ul class="help-topic-grid">
            @foreach ($sections as $section)
                <li>
                    <button type="button" class="help-topic" data-topic="{{ $section['id'] }}" data-title="{{ $section['title'] }}" aria-pressed="false">
                        <span class="help-topic-disc">{!! $icon($section['icon'], 22) !!}</span>
                        <span class="help-topic-title">{{ $section['title'] }}</span>
                        <span class="help-topic-count">{{ count($section['questions']) }} {{ Str::plural('question', count($section['questions'])) }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ---------- 3. FAQ + contact ---------- --}}
    <div class="help-columns">

        <article class="help-faq" aria-labelledby="help-faq-title">
            <header class="help-faq-head">
                <h2 id="help-faq-title">Frequently asked questions</h2>
                <span class="help-faq-count" id="help-shown" aria-live="polite">{{ $questionCount }} shown</span>
            </header>

            <div id="help-faq-list">
                @foreach ($sections as $section)
                    <section class="help-section" data-section="{{ $section['id'] }}" aria-labelledby="help-sec-{{ $section['id'] }}">
                        <h3 class="help-section-label" id="help-sec-{{ $section['id'] }}">{{ $section['title'] }}</h3>

                        <div class="help-q-list">
                            @foreach ($section['questions'] as $q)
                                <details class="help-q" id="{{ $q['id'] }}" data-search="{{ $q['search'] }}">
                                    <summary>
                                        <span class="help-q-text">{{ $q['q'] }}</span>
                                        <span class="help-chevron" aria-hidden="true">{!! $icon('chevron', 16) !!}</span>
                                    </summary>

                                    <div class="help-a">
                                        @foreach ($q['paragraphs'] as $paragraph)
                                            <p>{{ $paragraph }}</p>
                                        @endforeach

                                        @if ($q['list'] !== [])
                                            <ul class="{{ $q['badges'] ? 'help-status-list' : 'help-list' }}">
                                                @foreach ($q['list'] as $item)
                                                    <li>
                                                        @if ($q['badges'])
                                                            <span class="help-badge help-badge--{{ $item['tone'] }}">{{ $item['term'] }}</span>
                                                            <span>{{ $item['text'] }}</span>
                                                        @else
                                                            @if ($item['term'] !== '')<strong>{{ $item['term'] }}:</strong>@endif
                                                            {{ $item['text'] }}
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if ($q['link'])
                                            <a href="{{ $q['link']['url'] }}" class="help-a-link">{{ $q['link']['label'] }} <span aria-hidden="true">&rarr;</span></a>
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div class="help-empty" id="help-empty" hidden>
                    <p>No questions match &ldquo;<span id="help-empty-term"></span>&rdquo;. Try another word, or <a href="#contact">contact us</a> and we will help you directly.</p>
                </div>
            </div>

            @if ($showGuides)
                <footer class="help-faq-foot">
                    <p>Need step-by-step help with a specific form?</p>
                    {{-- TODO: point this at the module application guides once they exist. --}}
                    <a href="#" class="btn-secondary help-guides-btn">Open the application guides</a>
                </footer>
            @endif
        </article>

        <aside class="help-aside" id="contact" aria-label="{{ $contactMode === 'support' ? 'System support' : 'Contact the Centre for Graduate Studies' }}">
            <section class="help-contact" aria-labelledby="help-contact-title">
                @if ($contactMode === 'support')
                    {{-- CGS staff and the administrator: system support, never the CGS enquiry portal. --}}
                    <div class="help-contact-head">
                        <h2 id="help-contact-title">System support</h2>
                        <p>For account, role or system problems in UResearch.</p>
                    </div>

                    <div class="help-contact-body">
                        @if (empty($support['email']))
                            <p class="help-contact-muted">Support contact not set yet.</p>
                        @endif

                        <ul class="help-contact-rows">
                            @if (! empty($support['name']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('shield', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Contact</span>
                                        <span class="help-contact-value">{{ $support['name'] }}</span>
                                    </span>
                                </li>
                            @endif
                            @if (! empty($support['email']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('mail', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Email</span>
                                        <a href="mailto:{{ $support['email'] }}" class="help-contact-value">{{ $support['email'] }}</a>
                                    </span>
                                </li>
                            @endif
                            @if (! empty($support['hours']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('clock', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Hours</span>
                                        <span class="help-contact-value">{{ $support['hours'] }}</span>
                                    </span>
                                </li>
                            @endif
                        </ul>
                    </div>
                @else
                    <div class="help-contact-head">
                        <h2 id="help-contact-title">Contact us</h2>
                        <p>Still stuck? The Centre for Graduate Studies can help.</p>
                    </div>

                    <div class="help-contact-body">
                        @if (! empty($contact['portal_url']))
                            <a href="{{ $contact['portal_url'] }}" class="btn help-enquiry-btn" target="_blank" rel="noopener">
                                Submit an enquiry <span aria-hidden="true">&#8599;</span><span class="help-sr">(opens in a new tab)</span>
                            </a>
                            <p class="help-enquiry-caption">Opens the UTP Enquiries Portal</p>
                        @endif

                        <ul class="help-contact-rows">
                            @if (! empty($contact['phone']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('phone', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Hotline</span>
                                        <a href="{{ $telHref }}" class="help-contact-value">{{ $contact['phone'] }}</a>
                                    </span>
                                </li>
                            @endif
                            @if (! empty($contact['email']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('mail', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Email</span>
                                        <a href="mailto:{{ $contact['email'] }}" class="help-contact-value">{{ $contact['email'] }}</a>
                                    </span>
                                </li>
                            @endif
                            @if (! empty($contact['hours']))
                                <li>
                                    <span class="help-contact-icon">{!! $icon('clock', 18) !!}</span>
                                    <span class="help-contact-text">
                                        <span class="help-contact-label">Office hours</span>
                                        <span class="help-contact-value">{{ $contact['hours'] }}</span>
                                    </span>
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif
            </section>

            @if ($tips !== [])
                <section class="help-tips" aria-labelledby="help-tips-title">
                    <h3 id="help-tips-title">Before you get in touch</h3>
                    <ul>
                        @foreach ($tips as $tip)
                            <li>{{ $tip }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>

    {{-- ---------- 4. About us ---------- --}}
    <section class="help-about" id="about" aria-labelledby="help-about-title">
        <div class="help-about-grid">
            <div class="help-about-main">
                <span class="help-about-eyebrow">About us</span>
                <h2 id="help-about-title">{{ $about['heading'] ?? '' }}</h2>
                @foreach ($about['system'] ?? [] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach

                @if (! empty($about['tags']))
                    <ul class="help-tags" aria-label="What UResearch covers">
                        @foreach ($about['tags'] as $tag)
                            <li>{{ $tag }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="help-cgs">
                <h3>{{ $about['cgs_heading'] ?? 'Centre for Graduate Studies' }}</h3>
                <p>{{ $about['cgs_summary'] ?? '' }}</p>

                <ul class="help-people">
                    @foreach ($leadership as $person)
                        <li>
                            <span class="help-avatar help-avatar--gold" aria-hidden="true">{{ $person['initials'] }}</span>
                            <span class="help-person-text">
                                <span class="help-person-name">{{ $person['name'] }}</span>
                                <span class="help-person-title">{{ $person['title'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if (! empty($contact['website_url']))
                    <a href="{{ $contact['website_url'] }}" class="help-a-link" target="_blank" rel="noopener">
                        CGS on UTP Info <span aria-hidden="true">&rarr;</span><span class="help-sr">(opens in a new tab)</span>
                    </a>
                @endif
            </div>
        </div>

        @if ($units !== [])
            <div class="help-units">
                <h3>Who handles what</h3>
                <p class="help-units-sub">Write to the unit that looks after your request.</p>

                <ul class="help-unit-grid">
                    @foreach ($units as $unit)
                        <li class="help-unit">
                            <h4>{{ $unit['name'] }}</h4>
                            <p class="help-unit-topics">{{ $unit['topics'] }}</p>
                            <ul class="help-people help-people--compact">
                                @foreach ($unit['staff'] as $person)
                                    <li>
                                        <span class="help-avatar" aria-hidden="true">{{ $person['initials'] }}</span>
                                        <span class="help-person-text">
                                            <span class="help-person-name">{{ $person['name'] }}</span>
                                            <span class="help-person-title">{{ $person['title'] }}</span>
                                            @if (! empty($person['email']))
                                                {{-- <wbr> lets a long address wrap after the @, not mid-word. --}}
                                                <a href="mailto:{{ $person['email'] }}" class="help-person-email"><span>{{ Str::before($person['email'], '@') }}@<wbr>{{ Str::after($person['email'], '@') }}</span></a>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script @cspNonce>
(function () {
    var root = document.getElementById('help-root');
    if (! root) return;
    root.classList.add('is-enhanced');

    var input = document.getElementById('help-search');
    var title = document.getElementById('help-faq-title');
    var shown = document.getElementById('help-shown');
    var empty = document.getElementById('help-empty');
    var emptyTerm = document.getElementById('help-empty-term');
    var showAll = document.getElementById('help-show-all');
    var tiles = Array.prototype.slice.call(root.querySelectorAll('.help-topic'));
    var sections = Array.prototype.slice.call(root.querySelectorAll('.help-section'));
    var defaultTitle = title.textContent;
    var state = { term: '', topic: null };

    function apply() {
        var words = state.term.toLowerCase().split(/\s+/).filter(Boolean);
        var count = 0;

        sections.forEach(function (section) {
            var inTopic = ! state.topic || section.getAttribute('data-section') === state.topic;
            var visible = 0;

            section.querySelectorAll('.help-q').forEach(function (q) {
                var haystack = q.getAttribute('data-search') || '';
                var match = inTopic && words.every(function (w) { return haystack.indexOf(w) !== -1; });
                q.hidden = ! match;

                // Open what a search found; close only what the search opened.
                if (words.length && match) {
                    if (! q.open) { q.open = true; q.setAttribute('data-auto-open', ''); }
                } else if (q.hasAttribute('data-auto-open')) {
                    q.open = false;
                    q.removeAttribute('data-auto-open');
                }
                if (match) visible++;
            });

            section.hidden = visible === 0;
            count += visible;
        });

        var tile = state.topic ? root.querySelector('.help-topic[data-topic="' + state.topic + '"]') : null;
        title.textContent = words.length
            ? 'Results for “' + state.term + '”'
            : (tile ? tile.getAttribute('data-title') : defaultTitle);
        shown.textContent = count + ' shown';

        empty.hidden = count !== 0;
        emptyTerm.textContent = state.term;

        tiles.forEach(function (t) {
            t.setAttribute('aria-pressed', t.getAttribute('data-topic') === state.topic ? 'true' : 'false');
        });
        showAll.hidden = ! state.topic && ! words.length;
    }

    input.addEventListener('input', function () {
        state.term = input.value.trim();
        apply();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && input.value !== '') {
            input.value = '';
            state.term = '';
            apply();
        }
    });

    root.querySelectorAll('.help-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            input.value = pill.getAttribute('data-term');
            state.term = input.value;
            apply();
            input.focus();
        });
    });

    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () {
            var topic = tile.getAttribute('data-topic');
            state.topic = state.topic === topic ? null : topic;
            apply();
        });
    });

    showAll.addEventListener('click', function () {
        state.topic = null;
        state.term = '';
        input.value = '';
        apply();
        if (tiles[0]) tiles[0].focus();
    });

    // /help#apps-4 opens that answer, clearing any filter that hides it.
    function openFromHash() {
        var id = decodeURIComponent(location.hash.slice(1));
        if (! id) return;
        var q = document.getElementById(id);
        if (! q || ! q.classList.contains('help-q')) return;

        if (q.hidden) {
            state.term = '';
            state.topic = null;
            input.value = '';
            apply();
        }
        q.open = true;
        q.scrollIntoView({ block: 'start' });
        q.querySelector('summary').focus({ preventScroll: true });
    }

    window.addEventListener('hashchange', openFromHash);
    openFromHash();
})();
</script>
@endpush
