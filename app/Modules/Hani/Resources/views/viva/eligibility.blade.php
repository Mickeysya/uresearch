@extends('core::layouts.app')

@section('title', 'Viva: Eligibility and Notice of Thesis Submission')

@php
    use App\Modules\Hani\Support\VivaChecklist;
@endphp

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Viva: Eligibility and Notice of Thesis Submission"
        subtitle="Confirm each requirement from the Postgraduate Handbook, then give your Notice of Thesis Submission below, at least 3 months before your softbound thesis. Your Academic Executive will then contact your supervisor about appointing examiners." />

    @if ($level !== null && ! $openNotice)
        {{-- The form below has its own step bar, so the viva journey is one
             line of context here, not a second stepper. --}}
        @include('hani::partials.module_stepper', ['tracker' => $tracker, 'inline' => true, 'journey' => 'Your viva'])
    @elseif ($level !== null)
        {{-- On a card, not straight on the background photo: bare, its grey
             labels and hairlines disappeared into the sketch. --}}
        <div class="card card-wide" style="margin-bottom: var(--space-5)">
            @include('hani::partials.module_stepper', ['tracker' => $tracker])
        </div>
    @endif

    <div class="card card-wide">
        @if ($level === null)
            <p class="empty-state">
                Your programme is not recorded as MSc by Research or PhD, so the
                viva checklist does not apply. If that is wrong, ask CGS to
                correct the programme on your profile.
            </p>
        @else
            <p>
                Programme: <b>{{ auth()->user()->programme }}</b>
                ({{ $level === VivaChecklist::PHD ? 'PhD' : 'MSc by Research' }} requirements)
            </p>

            @if ($openNotice)
                @include('hani::viva._progress', ['progress' => $progress])

                <h3>Your Notice of Thesis Submission</h3>
                <table class="data-table">
                    <tbody>
                        <tr><th scope="row">Thesis title</th><td>{{ $progress->detail?->thesis_title }}</td></tr>
                        @if ($progress->detail?->keywords)
                            <tr><th scope="row">Keywords</th><td>{{ implode('; ', $progress->detail->keywords) }}</td></tr>
                        @endif
                        <tr><th scope="row">Expected softbound date</th><td>{{ $progress->detail?->expected_softbound_date?->format('j M Y') }}</td></tr>
                    </tbody>
                </table>

                <h3>Eligibility Checklist</h3>
                <p class="fstep-hint">Locked, because your notice was filed on it.</p>
            @endif

            {{-- Before a notice is filed this is a core::partials.form-stepper
                 wizard (Eligibility, Thesis, Date & proof, Review). After, it
                 is the same checklist, locked and read-only. --}}
            <form method="POST" action="{{ route('viva.notice.store') }}" enctype="multipart/form-data" id="viva-checklist"
                  @unless ($openNotice)
                      data-stepper
                      data-stepper-review="Check everything below, then submit. Your notice goes to your Academic Executive, who contacts your supervisor about appointing examiners."
                  @endunless>
                @csrf

                <fieldset @class(['fstep' => ! $openNotice]) data-label="Eligibility" @disabled($openNotice)>
                    @if (! $openNotice)
                        <p class="fstep-hint">Confirm each requirement from the Postgraduate Handbook. Save Progress keeps your ticks if you are not ready to file.</p>
                    @endif

                    {{-- Each requirement is one row: a real checkbox (restyled, still
                         native, so keyboard, required and the review step all work)
                         and its text. The whole row is the click target -- the
                         label's ::after stretches over it -- while the <label for>
                         holds only the requirement, so the review lists it cleanly. --}}
                    <div class="req-list">
                        @foreach ($items as $column => $item)
                            @php
                                $check = $checks[$column] ?? \App\Modules\Hani\Support\Eligibility\EligibilityResult::selfDeclared();
                                $bySystem = $check->status !== \App\Modules\Hani\Support\Eligibility\EligibilityResult::SELF_DECLARED;
                                $met = $bySystem ? ! $check->blocks() : (bool) old($column, $record?->$column);
                                $tone = match (true) {
                                    $check->blocks() => 'bad',
                                    $bySystem => 'ok',
                                    default => 'plain',
                                };
                            @endphp
                            <div @class(['req', 'is-system' => $bySystem, 'is-blocked' => $check->blocks()])>
                                {{-- An item the system checked cannot be ticked or unticked by hand. --}}
                                <input type="checkbox" class="req-box" @unless ($bySystem) name="{{ $column }}" @endunless id="{{ $column }}" value="1"
                                       data-check
                                       @checked($met) @disabled($bySystem) @required(! $bySystem && ! $openNotice)>
                                <div class="req-body">
                                    <div class="req-head">
                                        <label for="{{ $column }}">{{ $item['label'] }}</label>
                                        <span class="req-tag req-tag-{{ $tone }}">{{ $check->label() }}</span>
                                    </div>
                                    <p class="req-hint">{{ $check->message && $bySystem ? $check->message : $item['hint'] }}</p>
                                    @if ($check->blocks())
                                        <p class="field-error">{{ $check->message }}</p>
                                    @endif
                                    @error($column) <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="req-summary">
                        <strong><span data-count>{{ collect($items)->keys()->filter(fn ($c) => ($checks[$c] ?? null)?->status === 'verified' || (($checks[$c] ?? null)?->status === 'self_declared' && $record?->$c))->count() }}</span>
                        of {{ count($items) }} confirmed.</strong>
                        "Verified by system" items were checked against your candidature
                        record; the others are self-declared, and CGS may ask for evidence.
                    </p>

                </fieldset>

                @if (! $openNotice)
                    <fieldset class="fstep" data-label="Thesis" id="notice">
                        <label for="thesis_title">Thesis Title</label>
                        <input type="text" name="thesis_title" id="thesis_title" required maxlength="255"
                               value="{{ old('thesis_title') }}"
                               class="@error('thesis_title') is-invalid @enderror">
                        @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

                        <label for="abstract">Abstract</label>
                        <textarea name="abstract" id="abstract" rows="8" required maxlength="6000"
                                  data-words data-min="{{ $limits['abstract_min_words'] }}" data-max="{{ $limits['abstract_max_words'] }}"
                                  class="@error('abstract') is-invalid @enderror">{{ old('abstract') }}</textarea>
                        <p class="fstep-hint">
                            {{ $limits['abstract_min_words'] }} to {{ $limits['abstract_max_words'] }} words.
                            <span data-word-count>0</span> words so far. It is used to suggest examiners whose expertise matches your thesis.
                        </p>
                        @error('abstract') <p class="field-error">{{ $message }}</p> @enderror

                        <label for="keywords">Keywords</label>
                        <input type="text" name="keywords" id="keywords" required maxlength="1000"
                               value="{{ old('keywords') }}" placeholder="e.g. anomaly detection; time series; sensor data"
                               class="@error('keywords') is-invalid @enderror">
                        <p class="fstep-hint">{{ $limits['keywords_min'] }} to {{ $limits['keywords_max'] }} keywords, separated by semicolons.</p>
                        @error('keywords') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>

                    <fieldset class="fstep" data-label="Date &amp; proof">
                        <label for="expected_softbound_date">Expected Softbound Submission Date</label>
                        {{-- min mirrors the controller's 3-month rule so the browser
                             catches it before the server does. --}}
                        <input type="date" name="expected_softbound_date" id="expected_softbound_date" required
                               min="{{ $earliestSoftbound->toDateString() }}"
                               value="{{ old('expected_softbound_date') }}"
                               class="@error('expected_softbound_date') is-invalid @enderror">
                        <p class="fstep-hint">Earliest allowed: {{ $earliestSoftbound->format('j M Y') }}.</p>
                        @error('expected_softbound_date') <p class="field-error">{{ $message }}</p> @enderror

                        <label for="publication_proof">Publication Proof</label>
                        <input type="file" name="publication_proof" id="publication_proof" required
                               accept="application/pdf,.pdf"
                               class="@error('publication_proof') is-invalid @enderror">
                        <p class="fstep-hint">
                            PDF, up to {{ $proofMaxMb }} MB. The acceptance letter or the published article.
                            @if ($level === VivaChecklist::PHD)
                                If you are using two articles, combine them into one PDF.
                            @endif
                            @if ($errors->any())
                                Choose the file again: browsers do not keep a chosen file after an error.
                            @endif
                        </p>
                        @error('publication_proof') <p class="field-error">{{ $message }}</p> @enderror
                        <p class="fstep-hint" data-continue-hint>Confirm every requirement and choose your publication proof to submit.</p>
                    </fieldset>

                    {{-- The real submit must come first: the stepper moves the
                         form's first submit button into its nav, for the last
                         step. Save Progress is moved in beside Back below, so it
                         is there on every step. formaction + formnovalidate:
                         saving the ticks needs none of the notice fields. --}}
                    <button type="submit" data-continue>Submit Notice of Thesis Submission</button>
                    <button type="submit" class="btn-secondary" data-save-progress formaction="{{ route('viva.eligibility.save') }}" formnovalidate>
                        Save Progress
                    </button>
                @endif
            </form>
            @include('core::partials.form-stepper')
        @endif
    </div>
</div>

@push('head')
    <style @cspNonce>
        /* The eligibility checklist (this page only). */
        .req-list { display: grid; gap: var(--space-2); }

        .req {
            position: relative;
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            gap: var(--space-4);
            padding: var(--space-4) var(--space-5);
            border-radius: var(--radius-md);
            background: var(--surface-sunken);
            transition: background-color var(--duration-base) var(--ease);
        }

        .req:not(.is-system):hover { background: var(--surface-hover); }
        .req:has(.req-box:checked) { background: var(--accent-subtle); }
        .req.is-system:has(.req-box:checked) { background: var(--success-bg); }
        .req.is-blocked { background: var(--danger-bg); }

        /* The native box, restyled: 22px instead of the browser's 13px. */
        .req-box {
            position: relative;
            z-index: 1;      /* above the label's stretched ::after */
            appearance: none;
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            margin: 1px 0 0;
            padding: 0;
            border: 1.5px solid var(--border-strong);
            border-radius: 6px;
            background: var(--surface) center / 14px no-repeat;
            cursor: pointer;
            transition: background-color var(--duration-fast) var(--ease),
                        border-color var(--duration-fast) var(--ease),
                        transform var(--duration-fast) var(--ease);
        }

        .req-box:hover:not(:disabled) { border-color: var(--accent-solid); }
        .req-box:active:not(:disabled) { transform: scale(0.9); }

        .req-box:checked {
            background-color: var(--accent-solid);
            border-color: var(--accent-solid);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='white' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='3.5 8.5 6.5 11.5 12.5 4.5'/%3E%3C/svg%3E");
        }

        /* Checked by the system: not the student's to change, so it reads as
           settled (green, no pointer) rather than greyed out. */
        .req-box:disabled { cursor: default; opacity: 1; }
        .req-box:disabled:checked { background-color: var(--success-fg); border-color: var(--success-fg); }
        .req.is-blocked .req-box { border-color: var(--danger-fg); }

        /* Dark theme's --success-fg is a light green, so its tick goes dark. */
        :root[data-theme="dark"] .req-box:disabled:checked {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%2312301F' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='3.5 8.5 6.5 11.5 12.5 4.5'/%3E%3C/svg%3E");
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) .req-box:disabled:checked {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%2312301F' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='3.5 8.5 6.5 11.5 12.5 4.5'/%3E%3C/svg%3E");
            }
        }

        .req-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: var(--space-1) var(--space-3);
        }

        .req-head label {
            margin: 0;
            font-size: var(--text-md);
            font-weight: var(--weight-medium);
            color: var(--text-dark);
            cursor: pointer;
        }

        /* The whole row is the target, not just the 22px box. */
        .req-head label::after { content: ''; position: absolute; inset: 0; border-radius: inherit; }
        .req.is-system .req-head label { cursor: default; }

        .req-tag {
            font-size: var(--text-xs);
            font-weight: var(--weight-medium);
            color: var(--text-grey);
            white-space: nowrap;
        }

        .req-tag-ok  { color: var(--success-fg); }
        .req-tag-bad { color: var(--danger-fg); }

        .req-hint {
            margin: var(--space-1) 0 0;
            font-size: var(--text-sm);
            color: var(--text-body);
        }

        .req-body .field-error { position: relative; z-index: 1; margin: var(--space-2) 0 0; }

        .req-summary {
            margin: var(--space-5) 0 0;
            font-size: var(--text-sm);
            color: var(--text-grey);
        }

        .req-summary strong { color: var(--text-dark); font-weight: var(--weight-semi); }

        @media (prefers-reduced-motion: reduce) {
            .req, .req-box { transition: none; }
        }
    </style>
@endpush

@push('scripts')
    <script @cspNonce>
        // Unlocks "Submit" once every box is ticked and a PDF under the limit
        // is chosen, and counts the abstract's words. A convenience only:
        // VivaController::storeNotice() is the actual rule.
        (function () {
            var form = document.getElementById('viva-checklist');
            if (!form) return;

            var boxes = form.querySelectorAll('[data-check]');
            var button = form.querySelector('[data-continue]');
            var hint = form.querySelector('[data-continue-hint]');
            var count = form.querySelector('[data-count]');
            var proof = document.getElementById('publication_proof');
            var abstract = form.querySelector('[data-words]');
            var wordCount = form.querySelector('[data-word-count]');
            var maxBytes = {{ (int) $proofMaxMb }} * 1024 * 1024;

            function words(text) {
                var m = text.match(/[\p{L}\p{N}]+(?:['\-’][\p{L}\p{N}]+)*/gu);
                return m ? m.length : 0;
            }

            function sync() {
                var ticked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                count.textContent = ticked;
                if (!button) return;

                var file = proof && proof.files[0];
                var fileOk = !!file && /\.pdf$/i.test(file.name) && file.size <= maxBytes;
                if (proof) proof.setCustomValidity(file && !fileOk ? 'Choose a PDF of {{ (int) $proofMaxMb }} MB or smaller.' : '');

                var ready = ticked === boxes.length && fileOk;
                button.disabled = !ready;
                if (hint) hint.hidden = ready;
            }

            function syncWords() {
                if (!abstract) return;
                var n = words(abstract.value);
                wordCount.textContent = n;
                var min = +abstract.dataset.min, max = +abstract.dataset.max;
                abstract.setCustomValidity(abstract.value && (n < min || n > max) ? 'The abstract must be between ' + min + ' and ' + max + ' words.' : '');
            }

            // Runs after the stepper's script (it is included above), so its
            // nav exists. Without JS both buttons stay at the foot of the form.
            var save = form.querySelector('[data-save-progress]');
            var nav = form.querySelector('.fstep-nav');
            if (save && nav) {
                var cont = nav.querySelector('[data-fstep-next]');
                nav.insertBefore(save, cont);
                // Beside Continue, not floated to the middle: the nav CSS gives
                // every .btn-secondary margin-right:auto (meant for Back), and
                // submit buttons a different top margin.
                save.style.marginRight = '0';
                save.style.marginTop = getComputedStyle(cont).marginTop;
                // Three buttons do not fit a phone's width in one row.
                nav.style.flexWrap = 'wrap';
            }

            boxes.forEach(function (b) { b.addEventListener('change', sync); });
            if (proof) proof.addEventListener('change', function () { sync(); proof.reportValidity(); });
            if (abstract) abstract.addEventListener('input', syncWords);
            sync();
            syncWords();
        })();
    </script>
@endpush
@endsection
