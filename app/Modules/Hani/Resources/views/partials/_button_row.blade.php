{{-- A row of actions that live in separate forms (or a <details> whose
     <summary> is styled as a button), lined up and the same size.
     uresearch.css gives every <button> margin-top: 16px and margin-right: 8px,
     which a <summary class="btn-secondary"> does not get, so side by side
     they sat about 15px apart. Hani-only; nothing shared is changed.
     Usage: <div class="hani-btn-row"> <form>…<button>…</form> <details>…</details> </div>
     A <details> that opens takes the full width on its own line, so the
     buttons above it stay in place. --}}
@once
<style>
    .hani-btn-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: var(--space-2); }
    .hani-btn-row > form { margin: 0; display: flex; }
    .hani-btn-row > details { margin: 0; }
    .hani-btn-row > details[open] { flex-basis: 100%; }
    .hani-btn-row button,
    .hani-btn-row > details > summary {
        box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center;
        margin: 0; min-height: 40px; padding: 9px 18px;
        font-size: var(--text-sm); font-weight: var(--weight-semi); line-height: 1.2;
        border: 1px solid transparent; border-radius: var(--radius-sm); white-space: nowrap;
    }
    .hani-btn-row .btn-secondary,
    .hani-btn-row > details > summary.btn-secondary { border-color: var(--navy); }
    .hani-btn-row > details > summary { list-style: none; cursor: pointer; }
    .hani-btn-row > details > summary::-webkit-details-marker { display: none; }
    .hani-btn-row > details[open] > form { margin-top: var(--space-2); }
</style>
@endonce
