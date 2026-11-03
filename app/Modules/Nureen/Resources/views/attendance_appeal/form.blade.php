@extends('core::layouts.app')

@section('title', 'File an Attendance Appeal')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Attendance Appeal" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('attendance-appeal.store') }}" enctype="multipart/form-data" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Appeal details">
            <p class="fstep-hint">Why the recorded attendance is wrong, and anything that evidences it.</p>

            
            {{-- Which period, and how many of its missed sessions, this
                 appeal asks CGS to excuse. If CGS approves, those sessions
                 count as attended -- see AttendanceAppealController::decide().
                 "No specific period" keeps the old general dispute, which
                 changes no figures when approved. --}}
            <label for="attendance_record_id">Period this appeal is about</label>
            <select name="attendance_record_id" id="attendance_record_id" class="@error('attendance_record_id') is-invalid @enderror">
                <option value="">No specific period (a general query about my attendance)</option>
                @foreach ($periods as $period)
                    <option value="{{ $period->id }}" data-missed="{{ $period->sessionsMissed() }}"
                            @selected((string) old('attendance_record_id', $defaultPeriodId) === (string) $period->id)>
                        {{ 'Period ending '.$period->period_end->format('j M Y').': attended '.$period->sessions_attended.' of '.$period->sessions_total
                            .($period->sessions_excused > 0 ? ', '.$period->sessions_excused.' already excused' : '')
                            .', '.$period->sessionsMissed().' missed' }}
                    </option>
                @endforeach
            </select>
            @error('attendance_record_id') <p class="field-error">{{ $message }}</p> @enderror

            <label for="sessions_requested">Missed sessions this appeal covers</label>
            <input type="number" name="sessions_requested" id="sessions_requested" min="1" step="1"
                   value="{{ old('sessions_requested') }}" class="@error('sessions_requested') is-invalid @enderror">
            <p class="fstep-hint" id="sessions-covered-hint">For example, 4 if you missed four sessions while on medical leave. It cannot be more than the sessions missed in that period.</p>
            @error('sessions_requested') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason">Reason for Appeal</label>
            <textarea name="reason" id="reason" rows="4" required
                      class="@error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            {{-- CGS requires both, every time -- see
                 config('nureen.attendance_appeal.required_documents'). --}}
            <fieldset class="fstep" data-label="Documents">
            <p class="fstep-hint">A justification letter and your monthly activity breakdown are both required.</p>

            @foreach ($requiredDocuments as $field => $label)
                <x-nureen::multi-file-upload
                    :name="$field"
                    :label="$label"
                    :required="true" />
            @endforeach

            <label for="supporting_document">Other Supporting Document <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="supporting_document" id="supporting_document"
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Appeal</button>
        </form>
    </div>
</div>
@include('core::partials.form-stepper')

@push('scripts')
<script @cspNonce>
    (function () {
        var period = document.getElementById('attendance_record_id');
        var covered = document.getElementById('sessions_requested');
        if (! period || ! covered) return;

        // The count only applies to a chosen period, and can never be more
        // than that period's missed sessions -- the controller checks the
        // same thing.
        function sync() {
            var option = period.options[period.selectedIndex];
            var missed = option ? parseInt(option.getAttribute('data-missed') || '0', 10) : 0;
            var chosen = period.value !== '';
            covered.disabled = ! chosen;
            covered.required = chosen;
            covered.max = missed || '';
            if (chosen && ! covered.value) covered.value = missed || '';
        }

        period.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
@endsection
