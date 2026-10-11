@extends('core::layouts.app')

@section('title', 'New Travel Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Travel Application" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('travel.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Trip details">
            <label for="type_of_request">Type of Request</label>
            <select name="type_of_request" id="type_of_request" required
                    class="@error('type_of_request') is-invalid @enderror">
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

            <label for="travel_start_date">Start Date</label>
            {{-- min mirrors the controller's after_or_equal:today. Without it
                 the wizard's checkValidity() gate waves a past date through and
                 the student only finds out on submit. --}}
            <input type="date" name="travel_start_date" id="travel_start_date" required
                   min="{{ now()->toDateString() }}"
                   value="{{ old('travel_start_date') }}"
                   class="@error('travel_start_date') is-invalid @enderror">
            @error('travel_start_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="travel_end_date">End Date</label>
            <input type="date" name="travel_end_date" id="travel_end_date" required
                   value="{{ old('travel_end_date') }}"
                   class="@error('travel_end_date') is-invalid @enderror">
            @error('travel_end_date') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Duration is computed from the dates on save, so there is no
                 field for it here. --}}

            <label for="reason_for_travel">Reason for Travel</label>
            <input type="text" name="reason_for_travel" id="reason_for_travel" required
                   value="{{ old('reason_for_travel') }}"
                   class="@error('reason_for_travel') is-invalid @enderror">
            @error('reason_for_travel') <p class="field-error">{{ $message }}</p> @enderror

            <label for="destination_address">Destination</label>
            <input type="text" name="destination_address" id="destination_address" required
                   value="{{ old('destination_address') }}"
                   class="@error('destination_address') is-invalid @enderror">
            @error('destination_address') <p class="field-error">{{ $message }}</p> @enderror

            <div class="checkbox-row">
                <input type="checkbox" name="is_international" id="is_international" value="1"
                       @checked(old('is_international'))>
                <label for="is_international">International Travel</label>
            </div>
            </fieldset>

            {{-- International travel routes through CGS and the Dean as well,
                 so which of these two steps you are on does not change the
                 chain -- TravelWorkflow::stages() decides that from
                 is_international on submit. --}}
            <fieldset class="fstep" data-label="Contact &amp; documents">
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

            <label for="supporting_document">Supporting Document <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="supporting_document" id="supporting_document"
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">Required for international travel. Upload the
               approved memo here or later from your application page.</p>
            </fieldset>

            <fieldset class="fstep" data-label="Teaching Assistant (TA) Plan">
            <p class="fstep-hint">This section is compulsory for GA/GRA students.</p>

            <label>Are you a GA/GRA recipient?</label>
            @error('is_ga_gra') <p class="field-error">{{ $message }}</p> @enderror
            <div class="checkbox-row">
                <input type="radio" name="is_ga_gra" id="is_ga_gra_yes" value="yes" required
                       @checked(old('is_ga_gra') === 'yes')>
                <label for="is_ga_gra_yes" style="margin: 0;">Yes</label>
            </div>
            <div class="checkbox-row">
                <input type="radio" name="is_ga_gra" id="is_ga_gra_no" value="no" required
                       @checked(old('is_ga_gra') === 'no')>
                <label for="is_ga_gra_no" style="margin: 0;">No</label>
            </div>

            <fieldset id="ta-fields" style="border: none; padding: 0; margin: 0;"
                      @if (old('is_ga_gra') !== 'yes') hidden disabled @endif>
                <label for="ta_total_hours_required">Total TA hours required for the current semester</label>
                <input type="text" id="ta_total_hours_required" value="84 hours" readonly>

                <label for="ta_hours_completed">TA hours completed to date (hours)</label>
                <input type="number" name="ta_hours_completed" id="ta_hours_completed"
                       min="0" max="84" step="0.5" value="{{ old('ta_hours_completed') }}"
                       class="@error('ta_hours_completed') is-invalid @enderror">
                @error('ta_hours_completed') <p class="field-error">{{ $message }}</p> @enderror

                <label for="ta_hours_during_travel">TA hours scheduled during the travel period (hours)</label>
                <input type="number" name="ta_hours_during_travel" id="ta_hours_during_travel"
                       min="0" step="0.5" value="{{ old('ta_hours_during_travel') }}"
                       class="@error('ta_hours_during_travel') is-invalid @enderror">
                @error('ta_hours_during_travel') <p class="field-error">{{ $message }}</p> @enderror

                <label>How will the affected TA hours be managed</label>
                @error('ta_management_method') <p class="field-error">{{ $message }}</p> @enderror
                @foreach (\App\Modules\Norhanis\Models\TravelTaPlan::managementMethods() as $value => $labelText)
                    <div class="checkbox-row">
                        <input type="radio" name="ta_management_method" id="ta_management_method_{{ $value }}"
                               value="{{ $value }}" @checked(old('ta_management_method') === $value)>
                        <label for="ta_management_method_{{ $value }}" style="margin: 0;">{{ $labelText }}</label>
                    </div>
                @endforeach

                <fieldset id="ta-dates-wrap" style="border: none; padding: 0; margin: 0;"
                     @if (! in_array(old('ta_management_method'), \App\Modules\Norhanis\Models\TravelTaPlan::methodsNeedingDates(), true)) hidden disabled @endif>
                    <label>Proposed replacement/rescheduled date(s)</label>
                    @error('ta_plan_dates') <p class="field-error">{{ $message }}</p> @enderror
                    @error('ta_plan_dates.*') <p class="field-error">{{ $message }}</p> @enderror
                    <div id="ta-dates">
                        @foreach (old('ta_plan_dates', ['']) as $date)
                            <div class="ta-date-row" style="display: flex; gap: 8px; align-items: center; margin-top: 8px;">
                                <input type="date" name="ta_plan_dates[]" value="{{ $date }}" style="flex: 1;"
                                       @if ($loop->first) required @endif>
                                <button type="button" class="remove-ta-date" style="padding: 8px 12px;"
                                        @if ($loop->first) hidden @endif>Remove</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" id="add-ta-date">+ Add another date</button>
                </fieldset>
            </fieldset>

            <p class="message-info">For GA/GRA recipients only. Please provide your
               TA hours plan for the proposed travel period. The proposed TA hours
               arrangement must be reviewed and endorsed by RPP prior to the
               commencement of travel.</p>
            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>

<script @cspNonce>
    (function () {
        const gaGraYes = document.getElementById('is_ga_gra_yes');
        const gaGraNo = document.getElementById('is_ga_gra_no');
        const taFields = document.getElementById('ta-fields');

        function syncGaGra() {
            const show = gaGraYes.checked;
            taFields.hidden = ! show;
            taFields.disabled = ! show;
        }

        gaGraYes.addEventListener('change', syncGaGra);
        gaGraNo.addEventListener('change', syncGaGra);

        const methodRadios = document.querySelectorAll('input[name="ta_management_method"]');
        const datesWrap = document.getElementById('ta-dates-wrap');
        const needsDates = ['rescheduled_after_travel', 'replacement_arrangement'];

        function syncDates() {
            const picked = document.querySelector('input[name="ta_management_method"]:checked');
            const show = picked && needsDates.indexOf(picked.value) > -1;
            datesWrap.hidden = ! show;
            datesWrap.disabled = ! show;
        }

        methodRadios.forEach((radio) => radio.addEventListener('change', syncDates));

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
    })();
</script>

@include('core::partials.form-stepper')
@endsection
