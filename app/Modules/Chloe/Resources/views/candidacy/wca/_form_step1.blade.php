<section class="wca-card" aria-labelledby="wca-h-s1">
    <h3 id="wca-h-s1">Department and Supervisor / Programme Manager</h3>
    @if (! $department)
        <div class="wca-banner is-error" role="alert">Your department could not be found in your student record, so you cannot submit an appeal yet. Please contact the Centre for Graduate Studies.</div>
    @elseif (! $supervisor)
        <div class="wca-banner is-error" role="alert">Your Supervisor / Programme Manager is not recorded yet. Please contact CGS.</div>
    @else
        <p class="wca-muted wca-small">These details come from your student record and cannot be changed. The appeal will go on to these people.</p>
        <div class="wca-row">
            <div class="wca-field"><label for="wca-department-name">Department</label><input id="wca-department-name" class="wca-input" type="text" value="{{ $department->name }}" readonly>@error('department_id')<p class="wca-error">{{ $message }}</p>@enderror</div>
            <div class="wca-field"><label for="wca-programme-name">Programme</label><input id="wca-programme-name" class="wca-input" type="text" value="{{ $programme ?: '—' }}" readonly></div>
        </div>
        <div class="wca-row">
            <div class="wca-field"><label for="wca-supervisor">Supervisor / Programme Manager</label><input id="wca-supervisor" class="wca-input" type="text" value="{{ $supervisor->full_name }}" readonly></div>
            <div class="wca-field"><label for="wca-chair">Department Chair</label><input id="wca-chair" class="wca-input" type="text" value="{{ $chair?->full_name ?? '—' }}" readonly></div>
            <div class="wca-field"><label for="wca-dean">Dean of PGR</label><input id="wca-dean" class="wca-input" type="text" value="{{ $dean?->full_name ?? '—' }}" readonly></div>
        </div>
        @error('supervisor')<p class="wca-error">{{ $message }}</p>@enderror
    @endif
</section>
