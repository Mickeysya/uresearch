{{-- Study Candidacy assistant placeholder: the same robot and chat box as the Workstation assistant, with ONE static line.
     It makes no request, calls no model and stores nothing: the panel is opened and closed by chloe-candidacy.js. --}}
<div id="wca-assistant">
    <button type="button" id="wca-assistant-toggle" class="wca-assistant-toggle" aria-haspopup="dialog" aria-controls="wca-assistant-panel" aria-expanded="false" aria-label="Open Study Candidacy assistant" title="Study Candidacy assistant">
        <svg viewBox="0 0 24 24" class="wca-robot" aria-hidden="true">
            <circle cx="12" cy="2.6" r="1.4" fill="currentColor"></circle>
            <line x1="12" y1="4" x2="12" y2="6.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></line>
            <rect x="2.6" y="10.5" width="2" height="4.6" rx="1" fill="currentColor"></rect>
            <rect x="19.4" y="10.5" width="2" height="4.6" rx="1" fill="currentColor"></rect>
            <rect x="4.3" y="6.4" width="15.4" height="14.2" rx="4.4" fill="currentColor"></rect>
            <path d="M3.6 20.6 1.8 23.4l4.6-1.9Z" fill="currentColor"></path>
            <rect class="wca-visor" x="6.7" y="10.2" width="10.6" height="5.6" rx="2.4"></rect>
            <circle class="wca-pupil" cx="9.6" cy="13" r="1.05"></circle>
            <circle class="wca-pupil" cx="14.4" cy="13" r="1.05"></circle>
        </svg>
    </button>
    <div id="wca-assistant-panel" class="wca-assistant-panel" role="dialog" aria-label="Study Candidacy assistant" hidden>
        <div class="wca-assistant-head"><span>Study Candidacy assistant</span><button type="button" id="wca-assistant-close" class="wca-assistant-close" aria-label="Close">&times;</button></div>
        <div class="wca-assistant-body">The Study Candidacy assistant is coming soon.</div>
    </div>
</div>
