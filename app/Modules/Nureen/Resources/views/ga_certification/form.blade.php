@extends('core::layouts.app')

@section('title', 'GA/GRA Certification Letter')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA/GRA Certification Letter Request" />

    @if ($supervisorProblem)
        <div class="card card-wide" role="alert" style="border-color: var(--danger-border);">
            <p style="color: var(--danger-fg); margin: 0;">{{ $supervisorProblem }}</p>
        </div>
    @else
    @if ($otherDrafts->isNotEmpty())
        <div class="card card-wide" style="margin-bottom: var(--space-6);">
            <p style="margin: 0;">
                You have {{ $otherDrafts->count() === 1 ? 'a saved draft' : $otherDrafts->count().' saved drafts' }} of this form.
                <a href="{{ route('ga-certification.draft.edit', $otherDrafts->first()) }}">Continue the latest</a>
                or see <a href="{{ route('nureen.drafts') }}">My Drafts</a>.
            </p>
        </div>
    @endif
    <div class="card card-wide">
        {{-- Submit posts to store(); "Save draft" posts the same form to
             saveDraft() (formaction) without the browser's required checks
             (formnovalidate). A draft being continued carries its id, and
             the step the student was on is saved with it. --}}
        <form id="ga-certification-form" method="POST" action="{{ route('ga-certification.store') }}"
              enctype="multipart/form-data" data-stepper
              data-draft-form data-resume-step="{{ $detail?->draft_step ?? 0 }}">
            @csrf
            @if ($draft)
                <input type="hidden" name="draft_id" value="{{ $draft->id }}">
                <p class="queue-meta" style="margin-top: 0;">
                    Continuing your draft, last saved {{ \App\Modules\Nureen\Support\Drafts::lastSaved($draft)->format('j M Y, g:ia') }}.
                </p>
            @endif
            <input type="hidden" name="draft_step" value="{{ $detail?->draft_step ?? 0 }}" data-draft-step>
            @if ($downloadAttachment)
                <p class="message-success" role="status">
                    Your pre-filled attachment is downloading.
                    <a href="{{ $downloadAttachment }}" data-auto-download>Download it again</a>
                </p>
            @endif

            {{-- The sections follow CGS's official online form (1-5).
                 Option lists: config('nureen.ga_certification.options'). --}}

            {{-- ---- 1. Student details ---- --}}
            <fieldset class="fstep" data-label="Student details">
            <p class="fstep-hint">From your profile. If any of this is wrong, update your profile or contact CGS before submitting.</p>

            <dl class="cert-profile">
                <dt>Matric ID</dt><dd>{{ $student->matric_no ?? '—' }}</dd>
                <dt>Name</dt><dd>{{ $student->name }}</dd>
                <dt>Department</dt><dd>{{ $student->department ?? '—' }}</dd>
                <dt>Programme</dt><dd>{{ $student->programme ?? '—' }}</dd>
            </dl>

            @foreach (['study_level' => 'Level', 'level_of_study' => 'Level of Study', 'mode_of_study' => 'Mode of Study'] as $name => $label)
                <label for="{{ $name }}">{{ $label }}</label>
                <select name="{{ $name }}" id="{{ $name }}" required class="@error($name) is-invalid @enderror">
                    <option value="">Select&hellip;</option>
                    @foreach ($options[$name] as $value => $text)
                        <option value="{{ $value }}" @selected(old($name, $detail?->{$name}) === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                @error($name) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach

            {{-- GA and GRA are for full-time research students (Postgraduate
                 Handbook 2025). A note, not a block: a former GA may still
                 need a letter. CGS sees the same in its System checks. --}}
            <p class="message-warning" data-eligibility-note
               data-eligible="{{ json_encode(config('nureen.ga_certification.eligible')) }}"
               @unless (($detail?->study_level && $detail->study_level !== 'research') || ($detail?->mode_of_study && $detail->mode_of_study !== 'full_time')) hidden @endunless>
                GA and GRA appointments are for full-time research students. You can still request a letter (for example for an
                appointment you held before), and CGS will check it.
            </p>
            </fieldset>

            {{-- ---- 2. Application type ---- --}}
            <fieldset class="fstep" data-label="Application type">
            <p class="fstep-hint">Why you need the letter, and which kind of appointment it certifies.</p>

            <label for="purpose">Reason/Purpose <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="purpose" id="purpose" maxlength="{{ $purposeMax }}"
                   placeholder="e.g. scholarship application, visa renewal"
                   value="{{ old('purpose', $detail?->purpose) }}" data-char-counter aria-describedby="purpose-count"
                   class="@error('purpose') is-invalid @enderror">
            <p class="char-count" id="purpose-count" aria-live="polite">{{ mb_strlen((string) old('purpose', $detail?->purpose)) }} / {{ $purposeMax }} characters</p>
            @error('purpose') <p class="field-error">{{ $message }}</p> @enderror

            <label for="appointment_type">Type of Application</label>
            <select name="appointment_type" id="appointment_type" required
                    class="@error('appointment_type') is-invalid @enderror">
                <option value="">Select&hellip;</option>
                <option value="GA" @selected(old('appointment_type', $detail?->appointment_type) === 'GA')>Graduate Assistant (GA)</option>
                <option value="GRA" @selected(old('appointment_type', $detail?->appointment_type) === 'GRA')>Graduate Research Assistant (GRA)</option>
            </select>
            @error('appointment_type') <p class="field-error">{{ $message }}</p> @enderror

            {{-- config('nureen.ga_certification.ask_period'): CGS's online
                 form doesn't ask for the period; pending their answer. --}}
            @if ($askPeriod)
                <label for="period_start">Appointment period starts</label>
                <input type="date" name="period_start" id="period_start" required
                       value="{{ old('period_start', $detail?->period_start?->format('Y-m-d')) }}"
                       class="@error('period_start') is-invalid @enderror">
                @error('period_start') <p class="field-error">{{ $message }}</p> @enderror

                <label for="period_end">Appointment period ends</label>
                <input type="date" name="period_end" id="period_end" required
                       value="{{ old('period_end', $detail?->period_end?->format('Y-m-d')) }}"
                       class="@error('period_end') is-invalid @enderror">
                @error('period_end') <p class="field-error">{{ $message }}</p> @enderror
            @endif
            </fieldset>

            {{-- One step holding both types' fields: form-stepper shows every
                 .fstep in turn and sets `hidden` itself, so a fieldset per
                 type would put an empty "GRA details" step in front of a GA
                 applicant. syncType() below shows the right block. --}}
            <fieldset class="fstep" data-label="GA/GRA details">
            @foreach ($fields as $type => $typeFields)
            <div data-type-fields="{{ $type }}" hidden>
            <p class="fstep-hint">
                These fields apply to a {{ $type === 'GA' ? 'Graduate Assistant' : 'Graduate Research Assistant' }} appointment only.
            </p>

            @foreach ($typeFields as $key => $field)
                <label for="{{ $type }}-{{ $key }}">
                    {{ $field['label'] }}
                    @unless ($field['required'] ?? false)
                        <span style="color: var(--text-grey)">(optional)</span>
                    @endunless
                </label>
                {{-- 'max' from config keeps the letter on one page; the
                     same number is validated in CertificationController. --}}
                <input type="text" name="{{ $key }}" id="{{ $type }}-{{ $key }}" maxlength="{{ $field['max'] ?? 255 }}"
                       value="{{ old($key, $detail?->{$key}) }}" data-char-counter aria-describedby="{{ $type }}-{{ $key }}-count"
                       class="@error($key) is-invalid @enderror">
                <p class="char-count" id="{{ $type }}-{{ $key }}-count" aria-live="polite">{{ mb_strlen((string) old($key, $detail?->{$key})) }} / {{ $field['max'] ?? 255 }} characters</p>
                @error($key) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach
            </div>
            @endforeach
            <p class="fstep-hint" data-type-none>Choose GA or GRA under Application type first.</p>
            </fieldset>

            {{-- ---- 3. Supporting details ---- --}}
            <fieldset class="fstep" data-label="Supporting details">
            <p class="fstep-hint">Your identity document number, and the course owner's signed attachment.</p>

            @php($identityOnFile = filled($detail?->identity_number))
            <label for="identity_number">MyKad/Passport Number</label>
            {{-- Never shown back in full: stored encrypted, masked everywhere
                 but to CGS staff. --}}
            <input type="text" name="identity_number" id="identity_number" maxlength="30" autocomplete="off"
                   @unless ($identityOnFile) required @endunless
                   @if ($identityOnFile) aria-describedby="identity-on-file" @endif
                   class="@error('identity_number') is-invalid @enderror">
            @if ($identityOnFile)
                <p class="queue-meta" id="identity-on-file">On file: {{ $detail->identityFor(auth()->user()) }}. Leave blank to keep it.</p>
            @endif
            @error('identity_number') <p class="field-error">{{ $message }}</p> @enderror

            <div class="cert-attachment">
                <p><b>Supporting document:</b> the course owner signs CGS's attachment form. Download it already filled in with your
                    details, have it signed and stamped, then upload the signed copy below as a PDF.</p>
                <input type="hidden" name="download_attachment" value="0" data-download-attachment>
                {{-- A plain button: form-stepper treats the first submit
                     button in the form as Submit. It saves the draft (so the
                     PDF carries what is on screen) and the page then
                     downloads it. Without JavaScript the stepper doesn't
                     run, so a real submit button is safe there. --}}
                <button type="button" class="btn-secondary" data-attachment-download>Download pre-filled attachment (PDF)</button>
                @if ($draft)
                    <a href="{{ route('ga-certification.attachment.view', $draft) }}" target="_blank" rel="noopener" class="btn-secondary"
                       data-preview-url="{{ route('ga-certification.attachment.view', $draft) }}" data-preview-kind="pdf"
                       data-preview-name="GA/GRA certification attachment (as last saved)"
                       data-preview-download="{{ route('ga-certification.attachment', $draft) }}">View as last saved</a>
                @endif
                <noscript>
                    <button type="submit" class="btn-secondary" name="download_attachment" value="1" formnovalidate
                            formaction="{{ $draft ? route('ga-certification.draft.update', $draft) : route('ga-certification.draft.save') }}">Download pre-filled attachment (PDF)</button>
                </noscript>
            </div>

            <div data-type-fields="GA" hidden>
                <x-nureen::multi-file-upload
                    name="supporting_documents"
                    :label="$supportingDocumentLabel['GA']"
                    :required="true"
                    :allowed="$documentTypes"
                    :existing="$attachedDocuments" />
            </div>
            <div data-type-fields="GRA" hidden>
                <x-nureen::multi-file-upload
                    name="supporting_documents"
                    :label="$supportingDocumentLabel['GRA']"
                    :required="true"
                    :allowed="$documentTypes"
                    :existing="$attachedDocuments" />
            </div>
            </fieldset>

            {{-- ---- 4. Approval & notification ---- --}}
            <fieldset class="fstep" data-label="Approval &amp; notification">
            <p class="fstep-hint">Where updates about this request are sent.</p>

            <label for="personal_email">Personal email</label>
            <input type="email" name="personal_email" id="personal_email" maxlength="150" required autocomplete="email"
                   value="{{ old('personal_email', $detail?->personal_email) }}"
                   class="@error('personal_email') is-invalid @enderror">
            @error('personal_email') <p class="field-error">{{ $message }}</p> @enderror

            <dl class="cert-profile">
                <dt>Supervisor email</dt>
                <dd>{{ $student->supervisor?->email ?? 'No supervisor on record' }}@if ($student->supervisor) <span class="queue-meta">({{ $student->supervisor->name }}, from your profile)</span>@endif</dd>
            </dl>
            </fieldset>

            {{-- ---- 5. Agreement ---- --}}
            <fieldset class="fstep" data-label="Agreement">
            <p class="fstep-hint">Required before you submit. Not needed to save a draft.</p>

            <label class="cert-declaration">
                <input type="checkbox" name="declaration" value="1" required
                       @checked(old('declaration', $detail?->declaration_accepted_at ? '1' : null))
                       class="@error('declaration') is-invalid @enderror">
                <span>{{ $declaration }}</span>
            </label>
            @error('declaration') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Submit first: form-stepper treats the first submit button
                 as the form's own and moves it into its navigation. --}}
            <button type="submit">Submit Request</button>
            <button type="submit" class="btn-secondary" data-save-draft formnovalidate
                    formaction="{{ $draft ? route('ga-certification.draft.update', $draft) : route('ga-certification.draft.save') }}">Save draft</button>
        </form>
    </div>
    @endif
</div>
@include('core::partials.form-stepper')

@push('head')
<style>
    .char-count { margin: 2px 0 var(--space-3); font-size: var(--text-xs); color: var(--text-grey); text-align: right; }
    .char-count.is-full { color: var(--warning-fg); font-weight: var(--weight-semi); }
    .cert-profile { display: grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-4); margin: 0 0 var(--space-4); }
    .cert-profile dt { color: var(--text-grey); }
    .cert-profile dd { margin: 0; color: var(--text-dark); overflow-wrap: anywhere; }
    .cert-attachment { margin: var(--space-4) 0; padding: var(--space-3) var(--space-4); border: 1px dashed var(--border-grey); border-radius: var(--radius-md); }
    .cert-attachment p { margin: 0 0 var(--space-3); }
    .cert-declaration { display: flex; gap: var(--space-3); align-items: flex-start; font-weight: 400; }
    .cert-declaration input { margin-top: 3px; flex-shrink: 0; }
</style>
@endpush

@push('scripts')
<script @cspNonce>
    (function () {
        var form = document.getElementById('ga-certification-form');
        if (! form) return;

        var typeSelect = document.getElementById('appointment_type');

        // The eligibility note -- shown while the choices are not full-time research.
        var note = form.querySelector('[data-eligibility-note]');
        var eligible = JSON.parse(note.getAttribute('data-eligible'));
        function syncEligibility() {
            var off = Object.keys(eligible).some(function (name) {
                var value = form.querySelector('[name="' + name + '"]').value;
                return value !== '' && eligible[name].indexOf(value) === -1;
            });
            note.hidden = !off;
        }
        Object.keys(eligible).forEach(function (name) {
            form.querySelector('[name="' + name + '"]').addEventListener('change', syncEligibility);
        });
        syncEligibility();

        // Only the fields for the chosen type are shown, and only those
        // are required, so what the student sees matches exactly what
        // CertificationController::store() will validate.
        function syncType() {
            var value = typeSelect.value;
            form.querySelector('[data-type-none]').hidden = value !== '';

            form.querySelectorAll('[data-type-fields]').forEach(function (box) {
                var show = box.getAttribute('data-type-fields') === value;
                box.hidden = ! show;
                box.querySelectorAll('input[type="text"]').forEach(function (input) {
                    var label = box.querySelector('label[for="' + input.id + '"]');
                    input.required = show && ! (label && label.textContent.indexOf('(optional)') !== -1);
                    // Disabled, not just hidden: both blocks have a
                    // "semester" field, and a hidden-but-enabled one is still
                    // submitted -- the later (empty) one overwrote what a GA
                    // student had typed.
                    input.disabled = ! show;
                });
                box.querySelectorAll('input[type="file"]').forEach(function (input) {
                    // A draft's already-attached files satisfy "required".
                    input.required = show && ! input.hasAttribute('data-has-existing');
                    // A hidden block keeps its value otherwise -- cleared
                    // so switching type never submits the wrong type's
                    // files alongside the one actually shown.
                    if (! show) input.value = '';
                });
            });
        }

        typeSelect.addEventListener('change', syncType);
        syncType();

        // "Download pre-filled attachment": save the draft with a flag, so
        // the PDF carries what is on screen; the page that comes back
        // starts the download.
        var download = form.querySelector('[data-attachment-download]');
        var save = form.querySelector('[data-save-draft]');
        if (download && save) {
            download.addEventListener('click', function () {
                form.querySelector('[data-download-attachment]').value = '1';
                save.click();
            });
        }

        var ready = form.querySelector('[data-auto-download]');
        if (ready) window.location.assign(ready.href);

        // "12 / 120 characters" under every field that is printed on the
        // letter, so the limit is visible before it is hit.
        form.querySelectorAll('[data-char-counter]').forEach(function (input) {
            var counter = document.getElementById(input.getAttribute('aria-describedby'));
            var max = input.getAttribute('maxlength');

            function update() {
                counter.textContent = input.value.length + ' / ' + max + ' characters';
                counter.classList.toggle('is-full', input.value.length >= max);
            }

            input.addEventListener('input', update);
            update();
        });
    })();
</script>
@endpush
@endsection

{{-- Last, after the GA/GRA script above has set which fields are required
     and enabled: resuming a draft judges each step on that state. --}}
@include('nureen::partials.draft-form-script')
