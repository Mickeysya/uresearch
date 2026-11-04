@extends('core::layouts.app')

@section('title', 'New GA Extension Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA Extension Application" />

    {{-- The first stage is addressed to this supervisor, so there has to
         be one who can act -- Support\SupervisorCheck, enforced again by
         GaExtensionController::store(). --}}
    @if ($supervisorProblem)
        <div class="card card-wide" role="alert" style="margin-bottom: var(--space-6); border-color: var(--danger-border);">
            <p style="color: var(--danger-fg); margin: 0;">{{ $supervisorProblem }}</p>
        </div>
    @endif

    @if ($otherDrafts->isNotEmpty())
        <div class="card card-wide" style="margin-bottom: var(--space-6);">
            <p style="margin: 0;">
                You have {{ $otherDrafts->count() === 1 ? 'a saved draft' : $otherDrafts->count().' saved drafts' }} of this form.
                <a href="{{ route('ga-extension.draft.edit', $otherDrafts->first()) }}">Continue the latest</a>
                or see <a href="{{ route('nureen.drafts') }}">My Drafts</a>.
            </p>
        </div>
    @endif

    {{-- The generated form of each application already submitted, as it
         stands now (endorsements so far included). --}}
    @if ($submitted->isNotEmpty())
        <div class="card card-wide" style="margin-bottom: var(--space-6);">
            <h3 style="margin-top: 0;">Your GA Extension applications</h3>
            <ul class="ga-submitted">
                @foreach ($submitted as $application)
                    <li>
                        <span><b>#{{ $application->id }}</b> &middot; {{ ucfirst($application->status) }}
                            @if ($application->submitted_at) &middot; submitted {{ $application->submitted_at->format('j M Y') }} @endif</span>
                        <span>
                            <a href="{{ route('ga-extension.form-pdf.view', $application) }}" target="_blank" rel="noopener"
                               data-preview-url="{{ route('ga-extension.form-pdf.view', $application) }}" data-preview-kind="pdf"
                               data-preview-name="GA Extension Form #{{ $application->id }}"
                               data-preview-download="{{ route('ga-extension.form-pdf', $application) }}">View</a>
                            &middot;
                            <a href="{{ route('ga-extension.form-pdf', $application) }}">Download GA Extension Form (PDF)</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card card-wide">
        <p class="queue-meta" style="margin-top: 0;">
            CGS's GA Extension Form (UTP/CGS/001-A2) is generated from what you enter here: you no longer fill in and upload the form yourself.
        </p>

        {{-- Submit posts to store(); "Save draft" posts the same form to
             saveDraft() (formaction) without the browser's required checks
             (formnovalidate). A draft being continued carries its id, and
             the step the student was on is saved with it. --}}
        <form method="POST" action="{{ route('ga-extension.store') }}" enctype="multipart/form-data" data-stepper
              id="ga-extension-form" data-draft-form data-resume-step="{{ $detail?->draft_step ?? 0 }}">
            @csrf
            @if ($draft)
                <input type="hidden" name="draft_id" value="{{ $draft->id }}">
                <p class="queue-meta" style="margin-top: 0;">
                    Continuing your draft, last saved {{ \App\Modules\Nureen\Support\Drafts::lastSaved($draft)->format('j M Y, g:ia') }}.
                    <a href="{{ route('ga-extension.form-pdf.view', $draft) }}" target="_blank" rel="noopener"
                       data-preview-url="{{ route('ga-extension.form-pdf.view', $draft) }}" data-preview-kind="pdf"
                       data-preview-name="GA Extension Form (draft)" data-preview-download="{{ route('ga-extension.form-pdf', $draft) }}">View the form as last saved</a>
                    &middot; <a href="{{ route('ga-extension.form-pdf', $draft) }}">Download it</a>
                </p>
            @endif
            <input type="hidden" name="draft_step" value="{{ $detail?->draft_step ?? 0 }}" data-draft-step>
            @if ($downloadForm)
                <p class="message-success" role="status">
                    Your GA Extension Form is downloading.
                    <a href="{{ $downloadForm }}" data-auto-download>Download it again</a>
                </p>
            @endif

            {{-- ---- Section B (1 of 2) ---- --}}
            <fieldset class="fstep" data-label="Your details">
            <p class="fstep-hint">Section B of the form. Name, Student ID, Programme and Supervisor come from your profile; to change them, update your profile or contact CGS.</p>

            <dl class="ga-profile">
                <dt>Name</dt><dd>{{ $student->name }}</dd>
                <dt>Student ID</dt><dd>{{ $student->matric_no ?? '—' }}</dd>
                <dt>Programme</dt><dd>{{ $student->programme ?? '—' }}</dd>
                <dt>Supervisor</dt><dd>{{ $student->supervisor?->name ?? 'Not yet assigned' }}</dd>
            </dl>

            @foreach ($applicantFields as $key => $field)
                @continue(($field['step'] ?? 'details') !== 'details')
                @include('nureen::ga_extension._field', ['key' => $key, 'field' => $field, 'detail' => $detail])
            @endforeach

            {{-- config('nureen.ga_extension.allowed_levels'): when a level is
                 not allowed, say so here; store() refuses it on submit. --}}
            @if (count($allowedLevels) < count($applicantFields['level_of_study']['options']))
                <p class="message-warning" data-level-blocked hidden>
                    GA extension is open to {{ implode(' and ', $allowedLevels) }} students only, so this application can't be submitted.
                    Contact CGS if you think this is wrong.
                </p>
                <p class="queue-meta" data-level-note data-allowed-levels="{{ json_encode(array_keys($allowedLevels)) }}">
                    Open to {{ implode(' and ', $allowedLevels) }} students only.
                </p>
            @endif
            <p class="message-warning" data-eligibility-note hidden>
                GA is for full-time research students (Postgraduate Handbook 2025). You can still apply; CGS will see this when it reviews your application.
            </p>
            </fieldset>

            {{-- ---- Section B (2 of 2) ---- --}}
            <fieldset class="fstep" data-label="Identity{{ config('nureen.ga_extension.collect_bank_details') ? ' & bank' : '' }}">
            <p class="fstep-hint">Stored encrypted. Only CGS staff see these numbers in full; everyone else, you included, sees the last four characters.</p>

            @foreach ($applicantFields as $key => $field)
                @continue(($field['step'] ?? 'details') !== 'identity')
                @include('nureen::ga_extension._field', ['key' => $key, 'field' => $field, 'detail' => $detail])
            @endforeach
            </fieldset>

            <fieldset class="fstep" data-label="Extension details">
            <p class="fstep-hint">
                Your current end date, the date you are asking for, and why. The terms: an extension is
                {{ $rules['extension_months']['min'] }}–{{ $rules['extension_months']['max'] }} months, starts on the 1st or 16th
                (so the current appointment ends on the 15th or the last day of a month), ends on the 15th or the last day of a month,
                and is submitted at least {{ $rules['notice_months'] === 1 ? 'one month' : $rules['notice_months'].' months' }} before it starts.
                Backdated appointments are not allowed.
            </p>

            <label for="current_end_date">Current GA End Date</label>
            <input type="date" name="current_end_date" id="current_end_date" required
                   value="{{ old('current_end_date', $detail?->current_end_date?->format('Y-m-d')) }}"
                   class="@error('current_end_date') is-invalid @enderror">
            @error('current_end_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="requested_new_end_date">Requested New End Date</label>
            <input type="date" name="requested_new_end_date" id="requested_new_end_date" required
                   value="{{ old('requested_new_end_date', $detail?->requested_new_end_date?->format('Y-m-d')) }}"
                   class="@error('requested_new_end_date') is-invalid @enderror">
            @error('requested_new_end_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason_for_extension">Reason for Extension</label>
            <textarea name="reason_for_extension" id="reason_for_extension" rows="4" required
                      class="@error('reason_for_extension') is-invalid @enderror">{{ old('reason_for_extension', $detail?->reason_for_extension) }}</textarea>
            @error('reason_for_extension') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            {{-- ---- Section C ---- --}}
            <fieldset class="fstep" data-label="Publications">
            <p class="fstep-hint">
                Section C. List each publication; attach the paper itself as proof on the Supporting documents step.
                Leave this empty if you have none yet. Indexed in ISI/Scopus/ERA and Q1 or Q2 (MyRA standard) counts.
            </p>

            @php
                $rows = old('publications', $detail?->publicationRows() ?? []);
                $rows = is_array($rows) ? array_values($rows) : [];
            @endphp
            <div data-publications data-max="{{ $publicationOptions['max_rows'] }}">
                <div data-publication-list>
                    @foreach ($rows as $i => $row)
                        @include('nureen::ga_extension._publication_row', ['i' => $i, 'row' => $row, 'options' => $publicationOptions])
                    @endforeach
                    {{-- Without JavaScript there is no Add button: one blank row to fill. --}}
                    @if ($rows === [])
                        <noscript>@include('nureen::ga_extension._publication_row', ['i' => 0, 'row' => null, 'options' => $publicationOptions])</noscript>
                    @endif
                </div>
                <p class="queue-meta" data-publication-empty @if ($rows !== []) hidden @endif>No publications listed.</p>
                <button type="button" class="btn-secondary" data-publication-add>Add a publication</button>
                <template data-publication-template>
                    @include('nureen::ga_extension._publication_row', ['i' => '__INDEX__', 'row' => null, 'options' => $publicationOptions])
                </template>
            </div>
            @error('publications') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            @if ($additionalFields !== [])
            <fieldset class="fstep" data-label="Additional information">
            <p class="fstep-hint">A few extra details CGS has asked for.</p>

            @foreach ($additionalFields as $key => $field)
                <label for="additional_info_{{ $key }}">
                    {{ $field['label'] }}
                    @unless ($field['required'] ?? false)
                        <span style="color: var(--text-grey)">(optional)</span>
                    @endunless
                </label>
                @switch($field['type'] ?? 'text')
                    @case('textarea')
                        <textarea name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}" rows="4"
                                  @required($field['required'] ?? false)
                                  class="@error('additional_info.'.$key) is-invalid @enderror">{{ old("additional_info.$key", $detail?->additional_info[$key] ?? null) }}</textarea>
                        @break
                    @case('date')
                        <input type="date" name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}"
                               value="{{ old("additional_info.$key", $detail?->additional_info[$key] ?? null) }}"
                               @required($field['required'] ?? false)
                               class="@error('additional_info.'.$key) is-invalid @enderror">
                        @break
                    @default
                        <input type="text" name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}"
                               value="{{ old("additional_info.$key", $detail?->additional_info[$key] ?? null) }}"
                               @required($field['required'] ?? false)
                               class="@error('additional_info.'.$key) is-invalid @enderror">
                @endswitch
                @error('additional_info.'.$key) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach
            </fieldset>
            @endif

            {{-- ---- Section A ---- --}}
            <fieldset class="fstep" data-label="Supporting documents">
            <p class="fstep-hint">
                Section A's checklist. Items 1–3 are required: an application missing any of them is rejected here rather than reaching CGS.
            </p>

            @foreach ($requiredDocuments as $field => $label)
                <x-nureen::multi-file-upload
                    :name="$field"
                    :label="$label"
                    :required="true"
                    :existing="$attached->get($label, collect())" />
            @endforeach

            <h4 class="ga-subhead">Item 4: proof for your stage gate level</h4>
            <p class="queue-meta" data-stage-gate-hint
               data-gates="{{ json_encode($rules['stage_gates']) }}"
               data-proofs="{{ json_encode($stageGateDocuments) }}">
                Which one you need depends on how long since your admission: Level 2 needs an acceptance, Level 3 a published
                article, PhD Level 4 a soft bound submission. Level 1 (RPD) needs nothing here.
            </p>
            @foreach ($stageGateDocuments as $field => $label)
                <x-nureen::multi-file-upload
                    :name="$field"
                    :label="$label"
                    :required="false"
                    :existing="$attached->get($label, collect())" />
            @endforeach
            </fieldset>

            {{-- ---- Section D ---- --}}
            <fieldset class="fstep" data-label="Declaration">
            <p class="fstep-hint">Required before you submit. Not needed to save a draft.</p>

            <label class="ga-declaration">
                <input type="checkbox" name="declaration" value="1" required
                       @checked(old('declaration', $detail?->declaration_accepted_at ? '1' : null))
                       class="@error('declaration') is-invalid @enderror">
                <span>{{ $declaration }}</span>
            </label>
            @error('declaration') <p class="field-error">{{ $message }}</p> @enderror

            <div class="ga-form-preview">
                <p style="margin: 0 0 var(--space-3);">See the GA Extension Form CGS will receive, filled in from what you entered. It saves your draft first.</p>
                <input type="hidden" name="download_form" value="0" data-download-form>
                {{-- A plain button: form-stepper treats the first submit
                     button in the form as Submit. --}}
                <button type="button" class="btn-secondary" data-form-download>Download the form (PDF)</button>
                <noscript>
                    <button type="submit" class="btn-secondary" name="download_form" value="1" formnovalidate
                            formaction="{{ $draft ? route('ga-extension.draft.update', $draft) : route('ga-extension.draft.save') }}">Download the form (PDF)</button>
                </noscript>
            </div>
            </fieldset>

            {{-- Submit first: form-stepper treats the first submit button
                 as the form's own and moves it into its navigation. --}}
            <button type="submit">Submit Application</button>
            <button type="submit" class="btn-secondary" data-save-draft formnovalidate
                    formaction="{{ $draft ? route('ga-extension.draft.update', $draft) : route('ga-extension.draft.save') }}">Save draft</button>
        </form>
    </div>
</div>

@push('head')
<style>
    .ga-profile { display: grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-4); margin: 0 0 var(--space-4); }
    .ga-profile dt { color: var(--text-grey); }
    .ga-profile dd { margin: 0; color: var(--text-dark); overflow-wrap: anywhere; }
    .ga-choice { border: 0; padding: 0; margin: var(--space-3) 0 0; }
    .ga-choice legend { padding: 0; margin-bottom: var(--space-1); font-weight: var(--weight-semi); color: var(--text-dark); }
    .ga-choice-option { display: inline-flex; align-items: center; gap: var(--space-2); margin-right: var(--space-5); font-weight: 400; }
    .ga-choice-option input { width: auto; margin: 0; }
    .ga-pub { border: 1px solid var(--border-grey); border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-3); }
    .ga-pub-head { display: flex; justify-content: space-between; align-items: center; gap: var(--space-3); }
    .ga-pub-head button { padding: 4px 12px; font-size: var(--text-sm); }
    .ga-pub-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0 var(--space-4); }
    .ga-subhead { margin: var(--space-5) 0 var(--space-1); }
    .ga-declaration { display: flex; gap: var(--space-3); align-items: flex-start; font-weight: 400; }
    .ga-declaration input { margin-top: 3px; flex-shrink: 0; width: auto; }
    .ga-form-preview { margin-top: var(--space-5); padding: var(--space-3) var(--space-4); border: 1px dashed var(--border-grey); border-radius: var(--radius-md); }
    .ga-submitted { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
    .ga-submitted li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-2) var(--space-4); }
</style>
@endpush

@include('core::partials.form-stepper')

{{-- In the scripts stack, after form-stepper's own: the auto-download
     below navigates, and running it inline (before the stepper had set
     up) left the page without its steps. --}}
@push('scripts')
<script @cspNonce>
(function () {
    var form = document.getElementById('ga-extension-form');
    if (!form) return;

    // Allowed levels and eligibility: notes only, the server decides.
    var levelNote = form.querySelector('[data-level-note]');
    var levelBlocked = form.querySelector('[data-level-blocked]');
    var eligibility = form.querySelector('[data-eligibility-note]');
    function syncEligibility() {
        var level = form.querySelector('[name="level_of_study"]:checked');
        if (levelNote && levelBlocked) {
            var allowed = JSON.parse(levelNote.getAttribute('data-allowed-levels'));
            levelBlocked.hidden = !(level && allowed.indexOf(level.value) === -1);
        }
        var mode = form.querySelector('[name="mode_of_study"]').value;
        var type = form.querySelector('[name="study_type"]').value;
        eligibility.hidden = !((mode && mode !== 'full_time') || (type && type !== 'research'));
    }
    form.querySelectorAll('[name="level_of_study"], [name="mode_of_study"], [name="study_type"]').forEach(function (input) {
        input.addEventListener('change', syncEligibility);
    });
    syncEligibility();

    // Visa expiry: shown and required only for a student holding a visa.
    var visaOnly = form.querySelectorAll('[data-visa-only]');
    function syncVisa() {
        var yes = form.querySelector('[data-visa-toggle] input[value="1"]');
        var show = !!(yes && yes.checked);
        visaOnly.forEach(function (box) {
            box.hidden = !show;
            box.querySelectorAll('input').forEach(function (input) { input.required = show; input.disabled = !show; });
        });
    }
    form.querySelectorAll('[data-visa-toggle] input').forEach(function (input) { input.addEventListener('change', syncVisa); });
    syncVisa();

    // Section C: add/remove rows from the <template>.
    var box = form.querySelector('[data-publications]');
    var list = box.querySelector('[data-publication-list]');
    var template = box.querySelector('[data-publication-template]');
    var add = box.querySelector('[data-publication-add]');
    var empty = box.querySelector('[data-publication-empty]');
    var max = parseInt(box.getAttribute('data-max'), 10) || 10;
    var next = list.querySelectorAll('[data-publication-row]').length;

    function renumber() {
        var rows = list.querySelectorAll('[data-publication-row]');
        rows.forEach(function (row, n) { row.querySelector('[data-publication-number]').textContent = n + 1; });
        empty.hidden = rows.length > 0;
        add.hidden = rows.length >= max;
    }
    function wire(row) {
        row.querySelector('[data-publication-remove]').addEventListener('click', function () { row.remove(); renumber(); });
    }
    list.querySelectorAll('[data-publication-row]').forEach(wire);
    add.addEventListener('click', function () {
        var html = template.innerHTML.replace(/__INDEX__/g, String(next++));
        var holder = document.createElement('div');
        holder.innerHTML = html.trim();
        var row = holder.firstElementChild;
        list.appendChild(row);
        wire(row);
        renumber();
        row.querySelector('select, input').focus();
    });
    renumber();

    // Item 4: say which proof the student's level needs, from the admission
    // date and current end date. Only a hint -- GaExtensionRules decides.
    var hint = form.querySelector('[data-stage-gate-hint]');
    var original = hint.textContent;
    var gates = JSON.parse(hint.getAttribute('data-gates'));
    var proofs = JSON.parse(hint.getAttribute('data-proofs'));
    function monthsBetween(a, b) {
        var m = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
        return b.getDate() < a.getDate() ? m - 1 : m;
    }
    function syncGate() {
        var admission = form.querySelector('[name="admission_date"]').value;
        var end = form.querySelector('[name="current_end_date"]').value;
        var level = form.querySelector('[name="level_of_study"]:checked');
        if (!admission || !end || !level || !gates[level.value]) { hint.textContent = original; return; }
        var start = new Date(end + 'T00:00:00'); start.setDate(start.getDate() + 1);
        var months = Math.max(0, monthsBetween(new Date(admission + 'T00:00:00'), start));
        var found = 0;
        Object.keys(gates[level.value]).forEach(function (n) { if (months >= gates[level.value][n].from) found = Math.max(found, parseInt(n, 10)); });
        if (!found) { hint.textContent = months + ' months since admission when the extension starts: before the first stage gate, nothing to attach here.'; return; }
        var gate = gates[level.value][found];
        hint.textContent = months + ' months since admission when the extension starts: Stage Gate Level ' + found + ' (' + gate.requirement + '). '
            + (gate.proof ? 'Attach "' + proofs[gate.proof] + '".' : 'Nothing to attach here.');
    }
    ['admission_date', 'current_end_date'].forEach(function (name) { form.querySelector('[name="' + name + '"]').addEventListener('change', syncGate); });
    form.querySelectorAll('[name="level_of_study"]').forEach(function (input) { input.addEventListener('change', syncGate); });
    syncGate();

    // "Download the form (PDF)": save the draft with a flag; the page that
    // comes back starts the download.
    var download = form.querySelector('[data-form-download]');
    var save = form.querySelector('[data-save-draft]');
    if (download && save) {
        download.addEventListener('click', function () {
            form.querySelector('[data-download-form]').value = '1';
            save.click();
        });
    }
    var ready = form.querySelector('[data-auto-download]');
    if (ready) window.addEventListener('load', function () { window.location.assign(ready.href); });
})();
</script>
@endpush
@include('nureen::partials.draft-form-script')
<x-core::file-preview />
@endsection
