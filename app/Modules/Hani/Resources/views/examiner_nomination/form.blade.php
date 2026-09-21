@extends('core::layouts.app')

@section('title', 'Nominate Examiners')

@section('content')
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
            <form method="POST" action="{{ route('examiner-nomination.store') }}" data-stepper>
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
                            <option value="{{ $examiner->id }}"
                                    data-department="{{ $examiner->department }}"
                                    @selected(old($field) == $examiner->id)
                                    @disabled(! $examiner->isEligible())>
                                {{ $examiner->name }}, {{ $examiner->department }}
                                @unless ($examiner->isEligible())
                                    ({{ $examiner->stateLabel() }})
                                @endunless
                            </option>
                        @endforeach
                    </select>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                @endforeach
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
                            <option value="{{ $examiner->id }}"
                                    @selected(old($field) == $examiner->id)
                                    @disabled(! $examiner->isEligible())>
                                {{ $examiner->name }}@if ($examiner->institution), {{ $examiner->institution }}@endif
                                @unless ($examiner->isEligible())
                                    ({{ $examiner->stateLabel() }})
                                @endunless
                            </option>
                        @endforeach
                    </select>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                @endforeach
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
</div>

<h3 style="color: var(--navy); font-size: 15px;">Examiner pool</h3>
<table class="recent-activity-table">
    <thead>
        <tr><th>Name</th><th>Department</th><th>Type</th><th>State</th><th>Available from</th></tr>
    </thead>
    <tbody>
        @foreach ($internals->concat($externals)->sortBy('name') as $examiner)
            <tr>
                <td>{{ $examiner->name }}</td>
                <td>{{ $examiner->department }}</td>
                <td>{{ $examiner->typeLabel() }}</td>
                <td>{{ $examiner->stateLabel() }}</td>
                <td>
                    @if ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ON_GAP)
                        {{ $examiner->gapEndsOn()->format('j M Y') }}
                    @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED)
                        {{ $examiner->assigned_until->format('j M Y') }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

@include('core::partials.form-stepper')

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
