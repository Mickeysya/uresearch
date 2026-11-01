@php
    $selectedDept = old('department_id', $department?->id);
    $selectedSup = old('supervisor_profile_id', $appeal->supervisor_profile_id);
@endphp
<section class="wca-card" aria-labelledby="wca-h-s1">
    <h3 id="wca-h-s1">Department and supervisor</h3>
    <div class="wca-row">
        <div class="wca-field">
            <label for="wca-department">Department</label>
            <select id="wca-department" name="department_id" class="wca-select">
                <option value="">Choose your department</option>
                @foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) $selectedDept === $d->id)>{{ $d->name }}</option>@endforeach
            </select>
            @error('department_id')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
        <div class="wca-field">
            <label for="wca-supervisor">Supervisor</label>
            <select id="wca-supervisor" name="supervisor_profile_id" class="wca-select">
                <option value="">{{ $department ? 'Choose your supervisor' : 'Choose a department first' }}</option>
                @foreach ($lecturers as $l)<option value="{{ $l->id }}" @selected((int) $selectedSup === $l->id)>{{ $l->full_name }}</option>@endforeach
            </select>
            @error('supervisor_profile_id')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
    </div>
    <p class="wca-muted wca-small">For your information, the appeal will go on to these people. You cannot change them.</p>
    <div class="wca-row">
        <div class="wca-field"><label for="wca-chair">Department Chair</label><input id="wca-chair" class="wca-input" type="text" value="{{ $chair?->full_name ?? '—' }}" readonly></div>
        <div class="wca-field"><label for="wca-dean">Dean of PGR</label><input id="wca-dean" class="wca-input" type="text" value="{{ $dean?->full_name ?? '—' }}" readonly></div>
    </div>
</section>
