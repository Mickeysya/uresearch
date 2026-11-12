@extends('core::layouts.app')

@section('title', 'New Travel Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Travel Application" />

    @include('norhanis::partials.draft-banner')

    <div class="card card-wide">
        <form method="POST" action="{{ route('travel.store') }}" enctype="multipart/form-data" class="app-form" data-stepper
              data-draft-form data-resume-step="{{ $draftStep }}">
            @csrf
            @include('norhanis::partials.draft-fields')

            <fieldset class="fstep" data-label="Trip details">
            <label for="type_of_request">Type of Request</label>
            <select name="type_of_request" id="type_of_request" required
                    class="@error('type_of_request') is-invalid @enderror">
                {{-- Blank first, as on the other selects: without it the first
                     type showed as chosen and a student could skip past it. --}}
                <option value="">-- Select --</option>
                @foreach ($requestTypes as $value => $labelText)
                    <option value="{{ $value }}" @selected(old('type_of_request') === $value)>{{ $labelText }}</option>
                @endforeach
            </select>
            @error('type_of_request') <p class="field-error">{{ $message }}</p> @enderror

            <label for="other_request_specify">If "Others", please specify</label>
            <input type="text" name="other_request_specify" id="other_request_specify"
                   value="{{ old('other_request_specify') }}"
                   class="@error('other_request_specify') is-invalid @enderror">
            @error('other_request_specify') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Decides the final approver: the Senior Director CGS for local
                 travel, the Dean of PGR for international. --}}
            <label for="is_international">Local or International Travel</label>
            <select name="is_international" id="is_international" required
                    class="@error('is_international') is-invalid @enderror">
                <option value="">-- Select --</option>
                <option value="0" @selected(old('is_international') === '0')>Local</option>
                <option value="1" @selected(old('is_international') === '1')>International</option>
            </select>
            @error('is_international') <p class="field-error">{{ $message }}</p> @enderror

            <label for="travel_start_date">Start Date</label>
            {{-- min mirrors the controller's after_or_equal:today. Without it
                 the wizard's checkValidity() gate waves a past date through and
                 the student only finds out on submit. --}}
            <input type="date" name="travel_start_date" id="travel_start_date" required
                   min="{{ now()->toDateString() }}"
                   value="{{ old('travel_start_date') }}"
                   class="@error('travel_start_date') is-invalid @enderror">
            @error('travel_start_date') <p class="field-error">{{ $message }}</p> @enderror
            {{-- Filled in by the script below once both the start date and
                 Local / International are chosen. See TravelController::create(). --}}
            <p class="message-warning" id="lead-time-warning" hidden></p>

            <label for="travel_end_date">End Date</label>
            {{-- Never before the start date (same day is fine): the min follows
                 it, see partials/date-range. --}}
            <input type="date" name="travel_end_date" id="travel_end_date" required
                   min="{{ old('travel_start_date') ?: now()->toDateString() }}"
                   value="{{ old('travel_end_date') }}"
                   class="@error('travel_end_date') is-invalid @enderror">
            @error('travel_end_date') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Duration is computed from the dates on save, so there is no
                 field for it here. --}}

            <label for="reason_for_travel">Reason for Travelling</label>
            <input type="text" name="reason_for_travel" id="reason_for_travel" required
                   value="{{ old('reason_for_travel') }}"
                   class="@error('reason_for_travel') is-invalid @enderror">
            @error('reason_for_travel') <p class="field-error">{{ $message }}</p> @enderror

            <label for="destination_address">Destination While Travelling</label>
            <input type="text" name="destination_address" id="destination_address" required
                   value="{{ old('destination_address') }}"
                   class="@error('destination_address') is-invalid @enderror">
            @error('destination_address') <p class="field-error">{{ $message }}</p> @enderror

            <label for="cost_centre">Cost Centre to be utilized <span style="color: var(--text-grey)">(if any)</span></label>
            <input type="text" name="cost_centre" id="cost_centre" maxlength="100"
                   value="{{ old('cost_centre') }}"
                   class="@error('cost_centre') is-invalid @enderror">
            @error('cost_centre') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- TravelWorkflow::stages() picks the final approver from the
                 Local / International choice on step 1 when this is submitted. --}}
            <fieldset class="fstep" data-label="Contact &amp; documents">
            <p class="fstep-hint" style="font-weight: 600; color: var(--text-dark);">Contact Person While Travelling</p>

            <label for="contact_person_name">Contact Person Name</label>
            <input type="text" name="contact_person_name" id="contact_person_name"
                   value="{{ old('contact_person_name') }}">

            <label for="contact_person_no">Contact Person No</label>
            <input type="text" name="contact_person_no" id="contact_person_no"
                   value="{{ old('contact_person_no') }}">

            <label for="contact_person_email">Contact Person Email Address</label>
            <input type="email" name="contact_person_email" id="contact_person_email" required
                   value="{{ old('contact_person_email') }}"
                   class="@error('contact_person_email') is-invalid @enderror">
            @error('contact_person_email') <p class="field-error">{{ $message }}</p> @enderror

            @include('norhanis::partials.file-field', ['name' => 'supporting_document', 'label' => 'Supporting Document'])
            </fieldset>

            {{-- The GA/GRA module says whether this student holds an appointment
                 (Support\TaStatus). If it can: a GA/GRA gets this step with
                 their TA hours filled in, anyone else skips it entirely. Only
                 when nothing can say is the student asked, as before. --}}
            @if (! $ta['known'] || $ta['type'])
            <fieldset class="fstep" data-label="Teaching Assistant (TA) Plan">
            @if ($ta['known'])
                <p class="message-info">You hold an active {{ $ta['type'] }} appointment, so this TA plan is required.
                   Your TA hours completed this semester are the hours your course lecturer has approved on your
                   <a href="{{ route('ta-hours.index') }}">Daily Log TA Hours</a>. Submit any hours not yet logged, and have them approved, before you apply.</p>
            @else
            <p class="fstep-hint">This section is compulsory for GA/GRA students.</p>

            {{-- Drop-downs rather than radios here: the review step reads a
                 select's chosen text and its <label for>, so it shows
                 "Are you a GA/GRA recipient? Yes", not "Yes: yes". --}}
            <label for="is_ga_gra">Are you a GA/GRA recipient?</label>
            <select name="is_ga_gra" id="is_ga_gra" required
                    class="@error('is_ga_gra') is-invalid @enderror">
                <option value="">-- Select --</option>
                <option value="yes" @selected(old('is_ga_gra') === 'yes')>Yes</option>
                <option value="no" @selected(old('is_ga_gra') === 'no')>No</option>
            </select>
            @error('is_ga_gra') <p class="field-error">{{ $message }}</p> @enderror
            @endif

            <fieldset id="ta-fields" style="border: none; padding: 0; margin: 0;"
                      @if (! $ta['known'] && old('is_ga_gra') !== 'yes') hidden disabled @endif>
                <label for="ta_total_hours_required">Total TA hours required for the current semester</label>
                <input type="text" id="ta_total_hours_required" value="{{ $taHoursRequired }} hours" readonly>

                <label for="ta_hours_completed">TA hours completed to date (hours)</label>
                @if ($ta['known'])
                    {{-- Shown, never posted: store() reads the same figure itself. --}}
                    <input type="text" id="ta_hours_completed" readonly
                           value="{{ rtrim(rtrim(number_format($ta['hoursCompleted'], 2, '.', ''), '0'), '.') }} hours (approved since {{ $ta['from']->format('j M Y') }})">
                @else
                <input type="number" name="ta_hours_completed" id="ta_hours_completed"
                       min="0" max="{{ $taHoursRequired }}" step="0.5" value="{{ old('ta_hours_completed') }}"
                       class="@error('ta_hours_completed') is-invalid @enderror">
                @endif
                @error('ta_hours_completed') <p class="field-error">{{ $message }}</p> @enderror

                <label for="ta_hours_during_travel">TA hours scheduled during the travel period (hours)</label>
                <input type="number" name="ta_hours_during_travel" id="ta_hours_during_travel"
                       min="0" step="0.5" value="{{ old('ta_hours_during_travel') }}"
                       class="@error('ta_hours_during_travel') is-invalid @enderror">
                @error('ta_hours_during_travel') <p class="field-error">{{ $message }}</p> @enderror

                <label for="ta_management_method">How will the affected TA hours be managed</label>
                <select name="ta_management_method" id="ta_management_method" required
                        class="@error('ta_management_method') is-invalid @enderror">
                    <option value="">-- Select --</option>
                    @foreach (\App\Modules\Norhanis\Models\TravelTaPlan::managementMethods() as $value => $labelText)
                        <option value="{{ $value }}" @selected(old('ta_management_method') === $value)>{{ $labelText }}</option>
                    @endforeach
                </select>
                @error('ta_management_method') <p class="field-error">{{ $message }}</p> @enderror

                <fieldset id="ta-dates-wrap" style="border: none; padding: 0; margin: 0;"
                     @if (! in_array(old('ta_management_method'), \App\Modules\Norhanis\Models\TravelTaPlan::methodsNeedingDates(), true)) hidden disabled @endif>
                    <label>Proposed replacement/rescheduled date(s)</label>
                    @error('ta_plan_dates') <p class="field-error">{{ $message }}</p> @enderror
                    @error('ta_plan_dates.*') <p class="field-error">{{ $message }}</p> @enderror
                    <div id="ta-dates">
                        @foreach (old('ta_plan_dates', ['']) as $date)
                            <div class="ta-date-row" style="display: flex; gap: 8px; align-items: center; margin-top: 8px;">
                                {{-- Wrapped in a label so the review step names it. --}}
                                <label style="flex: 1; margin: 0;"><span hidden>Proposed date</span>
                                <input type="date" name="ta_plan_dates[]" value="{{ $date }}"
                                       @if ($loop->first) required @endif></label>
                                <button type="button" class="remove-ta-date" style="padding: 8px 12px;"
                                        @if ($loop->first) hidden @endif>Remove</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" id="add-ta-date">+ Add another date</button>
                </fieldset>
            </fieldset>

            <p class="message-info">Please provide your TA hours plan for the proposed travel
               period. The proposed TA hours arrangement must be reviewed and endorsed
               by RPP prior to the commencement of travel.</p>
            </fieldset>
            @endif

            <button type="submit">Submit Application</button>
            @include('norhanis::partials.draft-save')
        </form>
    </div>
</div>

<script @cspNonce>
    (function () {
        const gaGra = document.getElementById('is_ga_gra');
        const taFields = document.getElementById('ta-fields');

        // Only when the student is asked; otherwise the step is either
        // shown already or not on the page at all.
        if (gaGra) {
            gaGra.addEventListener('change', function () {
                const show = gaGra.value === 'yes';
                taFields.hidden = ! show;
                taFields.disabled = ! show;
            });
        }

        if (taFields) {

        const method = document.getElementById('ta_management_method');
        const datesWrap = document.getElementById('ta-dates-wrap');
        const needsDates = @json(\App\Modules\Norhanis\Models\TravelTaPlan::methodsNeedingDates());

        function syncDates() {
            const show = needsDates.indexOf(method.value) > -1;
            datesWrap.hidden = ! show;
            datesWrap.disabled = ! show;
        }

        method.addEventListener('change', syncDates);

        const list = document.getElementById('ta-dates');
        const add = document.getElementById('add-ta-date');

        function refreshRemoveButtons() {
            const rows = list.querySelectorAll('.ta-date-row');
            rows.forEach((row) => {
                row.querySelector('.remove-ta-date').hidden = rows.length <= 1;
            });
        }

        add.addEventListener('click', () => {
            const clone = list.querySelector('.ta-date-row').cloneNode(true);
            const input = clone.querySelector('input');
            input.value = '';
            input.required = false;
            list.appendChild(clone);
            refreshRemoveButtons();
        });

        list.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-ta-date')) {
                e.target.closest('.ta-date-row').remove();
                refreshRemoveButtons();
            }
        });

        refreshRemoveButtons();
        }

        // Lead time: warn when the trip starts before approval is expected.
        // Dates are compared as YYYY-MM-DD strings, which sort correctly.
        const leadTimes = @json($leadTimes);
        const start = document.getElementById('travel_start_date');
        const scope = document.getElementById('is_international');
        const warning = document.getElementById('lead-time-warning');

        function formatDate(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        }

        function syncLeadTime() {
            const lead = leadTimes[scope.value === '1' ? 'international' : 'local'];
            const late = scope.value !== '' && start.value !== '' && start.value <= lead.expected;

            warning.hidden = ! late;
            if (late) {
                warning.textContent = 'Approval usually takes about ' + lead.days + ' working days, so a decision is expected around '
                    + formatDate(lead.expected) + '. Your trip starts ' + formatDate(start.value)
                    + ', so it may not be approved in time. You can still submit.';
            }
        }

        start.addEventListener('change', syncLeadTime);
        scope.addEventListener('change', syncLeadTime);
        syncLeadTime();
    })();
</script>

@include('norhanis::partials.date-range', ['start' => 'travel_start_date', 'end' => 'travel_end_date'])
@include('core::partials.form-stepper')
@includeIf('nureen::partials.draft-form-script')
@include('norhanis::partials.file-remove')
@endsection
