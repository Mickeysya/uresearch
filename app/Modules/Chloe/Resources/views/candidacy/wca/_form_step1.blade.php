@php $selectedSup = old('supervisor_profile_id', $appeal->supervisor_profile_id); @endphp
<section class="wca-card" aria-labelledby="wca-h-s1">
    <h3 id="wca-h-s1">Department and supervisor</h3>
    @if (! $department)
        <div class="wca-banner is-error" role="alert">Your department could not be found in your student record, so you cannot submit an appeal yet. Please contact the Centre for Graduate Studies.</div>
    @else
        <div class="wca-field">
            <span class="wca-label">Department</span>
            <p class="wca-readonly" id="wca-department-name">{{ $department->name }}</p>
            <p class="wca-muted wca-small">Taken from your student record.@if ($department->faculty) {{ $department->faculty }}.@endif</p>
            @error('department_id')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
        <div class="wca-field">
            <label for="wca-supervisor">Supervisor</label>
            <select id="wca-supervisor" name="supervisor_profile_id" class="wca-select">
                <option value="">Choose your supervisor</option>
                @foreach ($lecturers as $l)<option value="{{ $l->id }}" @selected((int) $selectedSup === $l->id)>{{ $l->full_name }}</option>@endforeach
            </select>
            @error('supervisor_profile_id')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
        <p class="wca-muted wca-small">For your information, the appeal will go on to these people. You cannot change them.</p>
        <div class="wca-row">
            <div class="wca-field"><label for="wca-chair">Department Chair</label><input id="wca-chair" class="wca-input" type="text" value="{{ $chair?->full_name ?? '—' }}" readonly></div>
            <div class="wca-field"><label for="wca-dean">Dean of PGR</label><input id="wca-dean" class="wca-input" type="text" value="{{ $dean?->full_name ?? '—' }}" readonly></div>
        </div>
    @endif
</section>
