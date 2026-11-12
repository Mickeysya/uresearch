@extends('core::layouts.app')

@section('title', 'Nominate Examiners')

@section('content')
@include('hani::examiner_admin._pool_styles')

{{-- Page-scoped, tokens only, so it follows the dark theme. --}}
<style>
    .nomination-pool { margin-top: var(--space-6); }
    .nomination-pool h3 { margin: 0 0 var(--space-1); color: var(--navy); }
    .nomination-pool-note { margin: 0 0 var(--space-4); color: var(--text-grey); font-size: var(--text-sm); }
    .nomination-pool-date { white-space: nowrap; }
    .suggest-box { margin: 18px 0 6px; padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: 12px; background: var(--surface-sunken); }
    .suggest-box h4 { margin: 0 0 4px; color: var(--navy); font-size: var(--text-sm); }
    .suggest-note, .suggest-empty { margin: 0 0 8px; color: var(--text-grey); font-size: var(--text-xs); }
    .suggest-list { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 10px; }
    .suggest-item { font-size: var(--text-sm); }
    .suggest-head { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
    .suggest-score { padding: 1px 8px; border-radius: 999px; background: var(--info-bg); color: var(--info-fg); font-size: var(--text-2xs); font-weight: 700; }
    .suggest-where, .suggest-reasons { color: var(--text-grey); font-size: var(--text-xs); }
    .suggest-matched { font-size: var(--text-xs); }
    .suggest-flag { box-sizing: border-box; max-width: 320px; margin-top: 3px; padding: 3px 8px; border-radius: 8px; font-size: var(--text-xs);
        text-transform: none; white-space: normal; overflow-wrap: anywhere; }
    .suggest-flag details { font-weight: 400; }
    .suggest-flag summary { cursor: pointer; text-decoration: underline; }
    .suggest-flag details p { margin: 2px 0 0; }
    .suggest-flag.is-warning { background: var(--warning-bg); color: var(--warning-fg); }
    .suggest-flag.is-block { background: var(--danger-bg); color: var(--danger-fg); }
    .suggest-actions { display: flex; gap: 6px; margin-top: 4px; }
    .suggest-actions button { margin: 0; padding: 4px 10px; font-size: var(--text-xs); }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Examiner Nomination"
        subtitle="Four seats on the panel: a main and a backup on each side. The internal pair must come from the candidate's own department." />

    <div class="card card-wide">
        @if ($candidates->isEmpty())
            <div class="empty-state">
                You have no candidates assigned to you yet.<br>
                CGS assigns supervisees before nominations can be filed.
            </div>
        @else
            <form method="POST" action="{{ route('examiner-nomination.store') }}" data-stepper enctype="multipart/form-data"
                  data-suggest-url="{{ route('examiner-nomination.suggestions') }}">
                @csrf

                <fieldset class="fstep" data-label="Candidate">
                <p class="fstep-hint">Which of your supervisees this nomination is for, and the thesis being examined.</p>

                <label for="student_id">Candidate</label>
                {{-- data-department is read by the script at the foot of this
                     file: picking a candidate is what decides which internal
                     examiners are allowed. --}}
                <select name="student_id" id="student_id" required data-candidate
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">Select your candidate</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}"
                                data-department="{{ $candidate->department }}"
                                @selected(old('student_id') == $candidate->id)>
                            {{ $candidate->name }} @if ($candidate->matric_no)({{ $candidate->matric_no }})@endif
                            @if ($candidate->department), {{ $candidate->department }} @endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

                <label for="thesis_title">Thesis Title</label>
                <input type="text" name="thesis_title" id="thesis_title" required
                       value="{{ old('thesis_title') }}"
                       class="@error('thesis_title') is-invalid @enderror">
                @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Internal panel">
                <p class="fstep-hint">
                    From the candidate's own department, and nowhere else: an internal examiner from another
                    department is refused on submit. Anyone assigned, unavailable, or inside the
                    {{ \App\Modules\Hani\Models\Examiner::GAP_DAYS }}-day cooling-off period is greyed out here
                    and re-checked on submit.
                </p>

                @foreach (['internal_main_id' => 'Internal Main Examiner', 'internal_backup_id' => 'Internal Backup Examiner'] as $field => $label)
                    <label for="{{ $field }}">
                        {{ $label }}
                        @if ($field === 'internal_backup_id')<span style="color: var(--text-grey)">(optional)</span>@endif
                    </label>
                    <select name="{{ $field }}" id="{{ $field }}" data-internal
                            @required($field === 'internal_main_id')
                            class="@error($field) is-invalid @enderror">
                        <option value="">{{ $field === 'internal_main_id' ? 'Select' : 'None' }}</option>
                        @foreach ($internals as $examiner)
                            <option value="{{ $examiner->id }}" data-email-state="{{ $examiner->emailStatus() }}"
                                    data-department="{{ $examiner->department }}"
                                    @selected(old($field) == $examiner->id)
                                    @disabled(! $examiner->isEligible())>
                                {{ $examiner->name }}, {{ $examiner->department }}
                                @if ($examiner->emailInvalid()) [Email needs updating] @elseif ($examiner->needsEmail()) [Email needed] @endif
                                @unless ($examiner->isEligible())
                                    ({{ $examiner->stateLabel() }})
                                @endunless
                            </option>
                        @endforeach
                    </select>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    {{-- Conflict-of-interest flags for whoever is chosen here. --}}
                    <div data-coi-for="{{ $field }}" aria-live="polite"></div>
                    {{-- No working email for this examiner: ask the supervisor, who
                         usually knows. Proposed to CGS, never saved to the pool directly. --}}
                    <div class="email-ask" data-email-ask="{{ $field }}" @if (! old("proposed_email.$field") && ! $errors->has("proposed_email.$field")) hidden @endif>
                        <p class="fstep-hint" data-email-ask-why></p>
                        <label for="proposed_email_{{ $field }}">Examiner's current email <span style="color: var(--text-grey)">(optional, but saves CGS searching for it)</span></label>
                        <input type="email" name="proposed_email[{{ $field }}]" id="proposed_email_{{ $field }}" maxlength="150"
                               value="{{ old("proposed_email.$field") }}" class="@error("proposed_email.$field") is-invalid @enderror">
                        @error("proposed_email.$field") <p class="field-error">{{ $message }}</p> @enderror
                        <label for="proposed_cv_{{ $field }}">Examiner's CV <span style="color: var(--text-grey)">(optional, PDF up to 5 MB)</span></label>
                        <input type="file" name="proposed_cv[{{ $field }}]" id="proposed_cv_{{ $field }}" accept="application/pdf,.pdf"
                               class="@error("proposed_cv.$field") is-invalid @enderror">
                        @error("proposed_cv.$field") <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                {{-- Filled by public/js/hani/examiner-suggestions.js. --}}
                <div class="suggest-box">
                    <h4>Suggested internal examiners</h4>
                    <p class="suggest-note">Ranked by how well their expertise fits the thesis, from those you can nominate now. A suggestion is a starting point, not a decision.</p>
                    <div data-suggestions="internal" aria-live="polite"></div>
                </div>
                </fieldset>

                <fieldset class="fstep" data-label="External panel">
                <p class="fstep-hint">From outside UTP. Department does not constrain these; their institution and
                   faculty approval are what CGS checks instead.</p>

                @foreach (['external_main_id' => 'External Main Examiner', 'external_backup_id' => 'External Backup Examiner'] as $field => $label)
                    <label for="{{ $field }}">
                        {{ $label }}
                        @if ($field === 'external_backup_id')<span style="color: var(--text-grey)">(optional)</span>@endif
                    </label>
                    <select name="{{ $field }}" id="{{ $field }}"
                            @required($field === 'external_main_id')
                            class="@error($field) is-invalid @enderror">
                        <option value="">{{ $field === 'external_main_id' ? 'Select' : 'None' }}</option>
                        @foreach ($externals as $examiner)
                            <option value="{{ $examiner->id }}" data-email-state="{{ $examiner->emailStatus() }}"
                                    @selected(old($field) == $examiner->id)
                                    @disabled(! $examiner->isEligible())>
                                {{ $examiner->name }}@if ($examiner->institution), {{ $examiner->institution }}@endif
                                @if ($examiner->emailInvalid()) [Email needs updating] @elseif ($examiner->needsEmail()) [Email needed] @endif
                                @unless ($examiner->isEligible())
                                    ({{ $examiner->stateLabel() }})
                                @endunless
                            </option>
                        @endforeach
                    </select>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    {{-- Conflict-of-interest flags for whoever is chosen here. --}}
                    <div data-coi-for="{{ $field }}" aria-live="polite"></div>
                    {{-- No working email for this examiner: ask the supervisor, who
                         usually knows. Proposed to CGS, never saved to the pool directly. --}}
                    <div class="email-ask" data-email-ask="{{ $field }}" @if (! old("proposed_email.$field") && ! $errors->has("proposed_email.$field")) hidden @endif>
                        <p class="fstep-hint" data-email-ask-why></p>
                        <label for="proposed_email_{{ $field }}">Examiner's current email <span style="color: var(--text-grey)">(optional, but saves CGS searching for it)</span></label>
                        <input type="email" name="proposed_email[{{ $field }}]" id="proposed_email_{{ $field }}" maxlength="150"
                               value="{{ old("proposed_email.$field") }}" class="@error("proposed_email.$field") is-invalid @enderror">
                        @error("proposed_email.$field") <p class="field-error">{{ $message }}</p> @enderror
                        <label for="proposed_cv_{{ $field }}">Examiner's CV <span style="color: var(--text-grey)">(optional, PDF up to 5 MB)</span></label>
                        <input type="file" name="proposed_cv[{{ $field }}]" id="proposed_cv_{{ $field }}" accept="application/pdf,.pdf"
                               class="@error("proposed_cv.$field") is-invalid @enderror">
                        @error("proposed_cv.$field") <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="suggest-box">
                    <h4>Suggested external examiners</h4>
                    <p class="suggest-note">Ranked the same way. Conflict-of-interest flags are a pre-check; the examiner's own declaration (UTP/CGS/026) is still collected with the appointment letter.</p>
                    <div data-suggestions="external" aria-live="polite"></div>
                </div>
                </fieldset>

                <fieldset class="fstep" data-label="Notes">
                <p class="fstep-hint">Anything the Academic Executive should know when reviewing this nomination. Optional.</p>

                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="4">{{ old('notes') }}</textarea>
                </fieldset>

                <button type="submit">Submit Nomination</button>
            </form>
        @endif
    </div>

    {{-- For reference while choosing, and read-only: no emails, students, actions
         or duplicate warnings. Those are CGS's, on /examiners. Examiner names do
         not link anywhere because the examiner page is staff-only. --}}
    <section class="nomination-pool" aria-labelledby="pool-heading">
        <h3 id="pool-heading">Examiner pool</h3>
        <p class="nomination-pool-note">Read-only, and kept by CGS. Anyone Assigned, On Gap or Unavailable cannot be nominated right now.</p>

        @include('hani::examiner_admin._tabs', ['route' => 'examiner-nomination.create'])
        @include('hani::examiner_admin._filters', ['route' => 'examiner-nomination.create', 'stateLabel' => 'State'])

        <div class="exam-table-wrap">
            <table class="data-table exam-table">
                <thead>
                    <tr><th>Name</th><th>Department</th><th>Type</th><th>State</th><th>Available from</th></tr>
                </thead>
                <tbody>
                    @forelse ($examiners as $examiner)
                        <tr>
                            <td>
                                <div class="examiner-name-cell">
                                    @include('hani::examiner_admin._avatar')
                                    <span>{{ $examiner->name }}</span>
                                </div>
                            </td>
                            <td>{{ $examiner->department }}</td>
                            <td>@include('hani::examiner_admin._type_badge')</td>
                            <td>@include('hani::examiner_admin._state_badge')</td>
                            <td class="nomination-pool-date">
                                @if ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ON_GAP)
                                    {{ $examiner->gapEndsOn()->format('j M Y') }}
                                @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED)
                                    {{ $examiner->assigned_until->format('j M Y') }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state">No examiners found. Try adjusting your filters.</div></td></tr>
                    @endforelse
                </tbody>
            </table>

            @include('hani::examiner_admin._pagination')
        </div>
    </section>
</div>

@include('core::partials.region-swap')
{{-- A filter must not reload the page: the wizard above may be half filled in,
     and RegionSwap replaces only the pool's regions. Without JS these are plain
     GET links and forms. --}}
<script @cspNonce>
    RegionSwap({ selectors: ['.exam-tabs', '.examiner-filters', '.exam-table-wrap'] });
</script>

@include('core::partials.form-stepper')
<script src="{{ asset('js/hani/examiner-suggestions.js') }}" defer></script>

<script @cspNonce>
    // Ask for the examiner's email when the one chosen has none on record,
    // or one reported not working. The server ignores a proposal for an
    // examiner whose email works, so this only decides what is shown.
    (function () {
        document.querySelectorAll('[data-email-ask]').forEach(function (box) {
            var select = document.getElementById(box.dataset.emailAsk);
            var why = box.querySelector('[data-email-ask-why]');
            if (! select) return;

            function apply() {
                var option = select.selectedOptions[0];
                var state = option ? option.dataset.emailState : '';
                var ask = state === 'missing' || state === 'invalid';
                box.hidden = ! ask;
                box.querySelectorAll('input').forEach(function (input) { input.disabled = ! ask; });
                why.textContent = state === 'invalid'
                    ? 'The email CGS has for this examiner was reported not working. If you know their current email, please give it here.'
                    : 'CGS has no email for this examiner yet. If you know it, please give it here.';
            }

            select.addEventListener('change', apply);
            apply();
        });
    })();
</script>

<script @cspNonce>
    // The department rule, shown rather than only enforced. The server
    // refuses a mismatched internal examiner either way
    // (ExaminerNominationController::store()); this just stops the supervisor
    // finding out after filling the whole form in. Eligibility is left alone
    // -- those options are already disabled server-side.
    (function () {
        var candidate = document.querySelector('[data-candidate]');
        var internals = document.querySelectorAll('[data-internal]');
        if (! candidate || ! internals.length) return;

        function department() {
            var picked = candidate.options[candidate.selectedIndex];
            return picked ? (picked.getAttribute('data-department') || '') : '';
        }

        function apply() {
            var wanted = department();

            internals.forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (option) {
                    if (! option.value) return;

                    var own = option.getAttribute('data-department') || '';
                    var wrongDepartment = wanted !== '' && own !== wanted;

                    // Never re-enable what the server disabled for being
                    // ineligible: that decision is not ours to reverse.
                    if (option.dataset.ineligible === undefined) {
                        option.dataset.ineligible = option.disabled ? '1' : '0';
                    }

                    option.hidden = wrongDepartment;
                    option.disabled = option.dataset.ineligible === '1' || wrongDepartment;
                });

                if (select.selectedOptions.length && select.selectedOptions[0].hidden) {
                    select.value = '';
                }
            });
        }

        candidate.addEventListener('change', apply);
        apply();
    })();
</script>
@endsection
