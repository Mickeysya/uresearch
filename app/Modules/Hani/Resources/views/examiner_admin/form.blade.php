@extends('core::layouts.app')

@section('title', 'Add Examiner')

@section('content')
<style>
    .external-fields {
        margin: 20px 0 8px; padding: 16px 18px 4px;
        border: 1px solid var(--border-grey); border-radius: 12px;
        background: var(--surface-sunken);
    }
    .external-fields legend {
        padding: 0 8px; font-size: var(--text-sm); font-weight: 700; color: var(--navy);
    }
    .external-fields[hidden] { display: none; }
    .external-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0 14px; }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Add Examiner" />

    <div class="card card-wide">
        {{-- A wizard because the external sheet pushed this form to thirteen
             fields, past the eight `docs/conventions.md` puts the line at. The
             split is the one the two CGS sheets already make: who the person
             is, then the record kept only for someone from outside UTP.

             data-stepper-review because the generated review's default line
             says the form goes to an approver. This one does not go anywhere
             -- it writes a row into the pool. --}}
        <form method="POST" action="{{ route('examiner-admin.store') }}" data-stepper
              data-stepper-review="Check the details, then add the examiner. Nothing here is submitted for approval. The examiner simply joins the pool and becomes available to nominate.">
            @csrf

            <fieldset class="fstep" data-label="Examiner">
            <p class="fstep-hint">Who this person is. The same five fields for internal and external.</p>

            <label for="name">Name</label>
            <input type="text" name="name" id="name" required value="{{ old('name') }}"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="department">Department</label>
            <input type="text" name="department" id="department" required value="{{ old('department') }}"
                   class="@error('department') is-invalid @enderror">
            @error('department') <p class="field-error">{{ $message }}</p> @enderror

            <label for="faculty">Faculty <span style="color: var(--text-grey)">(optional, e.g. FOE, FSMC)</span></label>
            <input type="text" name="faculty" id="faculty" value="{{ old('faculty') }}"
                   class="@error('faculty') is-invalid @enderror">
            @error('faculty') <p class="field-error">{{ $message }}</p> @enderror

            <label for="type">Type</label>
            <select name="type" id="type" required class="@error('type') is-invalid @enderror">
                <option value="internal" @selected(old('type', 'internal') == 'internal')>Internal: UTP staff</option>
                <option value="external" @selected(old('type') == 'external')>External: from outside UTP</option>
            </select>
            @error('type') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Step two is the external sheet. It stays a step for an internal
                 examiner rather than disappearing: the stepper's rail is built
                 once, and a step count that changes under you as you pick a
                 type is worse than a step that says it does not apply. --}}
            <fieldset class="fstep" data-label="External record">
            <p class="fstep-hint" id="external-hint-external" @if (old('type') !== 'external') hidden @endif>
                The columns CGS keeps on the external sheet. All optional except
                the institution.
            </p>
            <p class="fstep-hint" id="external-hint-internal" @if (old('type') === 'external') hidden @endif>
                Nothing to record here. Internal examiners are UTP staff and carry
                no external paperwork, so continue to the review.
            </p>

            {{-- Hidden *and* disabled together, both driven by Type. Hidden is
                 the convenience; disabled is what makes it true -- a disabled
                 fieldset submits none of its controls, so an external record
                 half-typed and then switched to internal cannot reach the
                 controller at all. The controller's Arr::except stays as the
                 guard that does not depend on the browser. --}}
            <fieldset id="external-fields" class="external-fields"
                      @if (old('type') !== 'external') hidden disabled @endif>
                <legend>External examiner record</legend>

                <label for="institution">University / Industry</label>
                <input type="text" name="institution" id="institution" value="{{ old('institution') }}"
                       placeholder="Universiti Tun Hussein Onn Malaysia (UTHM)"
                       class="@error('institution') is-invalid @enderror">
                @error('institution') <p class="field-error">{{ $message }}</p> @enderror

                <label for="sector">Technical or Research</label>
                <select name="sector" id="sector" class="@error('sector') is-invalid @enderror">
                    <option value="">Not recorded</option>
                    <option value="technical" @selected(old('sector') == 'technical')>Technical</option>
                    <option value="research" @selected(old('sector') == 'research')>Research</option>
                </select>
                @error('sector') <p class="field-error">{{ $message }}</p> @enderror

                <label for="faculty_approval">Faculty Approval <span style="color: var(--text-grey)">(optional, e.g. 2.2023)</span></label>
                <input type="text" name="faculty_approval" id="faculty_approval" value="{{ old('faculty_approval') }}"
                       class="@error('faculty_approval') is-invalid @enderror">
                @error('faculty_approval') <p class="field-error">{{ $message }}</p> @enderror

                <label for="utp_cluster">UTP Academic Cluster <span style="color: var(--text-grey)">(optional)</span></label>
                <input type="text" name="utp_cluster" id="utp_cluster" value="{{ old('utp_cluster') }}"
                       placeholder="Separation Technology"
                       class="@error('utp_cluster') is-invalid @enderror">
                @error('utp_cluster') <p class="field-error">{{ $message }}</p> @enderror

                <label for="expertise">Area of Expertise <span style="color: var(--text-grey)">(optional)</span></label>
                <textarea name="expertise" id="expertise" rows="3" maxlength="2000"
                          placeholder="1. Applied Chemical Sciences"
                          class="@error('expertise') is-invalid @enderror">{{ old('expertise') }}</textarea>
                @error('expertise') <p class="field-error">{{ $message }}</p> @enderror

                <div class="external-figures">
                    <div>
                        <label for="years_experience">Years of experience</label>
                        <input type="number" name="years_experience" id="years_experience" min="0" max="80"
                               value="{{ old('years_experience') }}" class="@error('years_experience') is-invalid @enderror">
                        @error('years_experience') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="msc_graduated">MSc graduated</label>
                        <input type="number" name="msc_graduated" id="msc_graduated" min="0" max="999"
                               value="{{ old('msc_graduated') }}" class="@error('msc_graduated') is-invalid @enderror">
                        @error('msc_graduated') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phd_graduated">PhD graduated</label>
                        <input type="number" name="phd_graduated" id="phd_graduated" min="0" max="999"
                               value="{{ old('phd_graduated') }}" class="@error('phd_graduated') is-invalid @enderror">
                        @error('phd_graduated') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
            </fieldset>

            <button type="submit">Add Examiner</button>
        </form>

        @include('core::partials.form-stepper')
    </div>
</div>

<script @cspNonce>
    (function () {
        var type = document.getElementById('type');
        var fields = document.getElementById('external-fields');
        var hints = {
            external: document.getElementById('external-hint-external'),
            internal: document.getElementById('external-hint-internal'),
        };

        type.addEventListener('change', function () {
            var external = type.value === 'external';

            fields.hidden = ! external;
            fields.disabled = ! external;
            hints.external.hidden = ! external;
            hints.internal.hidden = external;
        });
    })();
</script>
@endsection
