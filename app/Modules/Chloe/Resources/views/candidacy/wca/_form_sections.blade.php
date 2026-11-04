@php
    $v = fn ($key, $default = null) => old($key, $appeal->{$key} ?? $default);
    $rcs = $v('rcs_status');
    $pubs = old('publications', $appeal->publications->map(fn ($p) => ['title' => $p->title, 'venue' => $p->venue, 'status' => $p->status, 'year' => $p->year])->all());
    $pubs = array_values($pubs);
@endphp
<section class="wca-card" aria-labelledby="wca-h-a">
    <h3 id="wca-h-a">Section A: Research progress</h3>
    <div class="wca-row">
        <div class="wca-field">
            <label for="wca-phase">Current phase</label>
            <select id="wca-phase" name="phase" class="wca-select">
                <option value="">Choose</option>
                @foreach ($phases as $key => $label)<option value="{{ $key }}" @selected($v('phase') === $key)>{{ $label }}</option>@endforeach
            </select>
            @error('phase')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
        <div class="wca-field">
            <label for="wca-percent">Thesis writing completed (%)</label>
            <input id="wca-percent" name="percent_complete" class="wca-input" type="number" min="0" max="100" value="{{ $v('percent_complete') }}">
            @error('percent_complete')<p class="wca-error">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="wca-field">
        <span class="wca-label">RCS (Research Competency Seminar) status</span>
        <label class="wca-choice"><input type="radio" name="rcs_status" value="done" @checked($rcs === 'done')> Done</label>
        <label class="wca-choice"><input type="radio" name="rcs_status" value="pending" @checked($rcs === 'pending')> Pending</label>
        @error('rcs_status')<p class="wca-error">{{ $message }}</p>@enderror
    </div>
    <div class="wca-row">
        <div class="wca-field" data-rcs="done"><label for="wca-rcs-date">RCS date</label><input id="wca-rcs-date" name="rcs_date" class="wca-input" type="date" value="{{ old('rcs_date', $appeal->rcs_date?->format('Y-m-d')) }}">@error('rcs_date')<p class="wca-error">{{ $message }}</p>@enderror</div>
        <div class="wca-field" data-rcs="pending"><label for="wca-rcs-exp">Expected RCS date</label><input id="wca-rcs-exp" name="rcs_expected_date" class="wca-input" type="date" value="{{ old('rcs_expected_date', $appeal->rcs_expected_date?->format('Y-m-d')) }}">@error('rcs_expected_date')<p class="wca-error">{{ $message }}</p>@enderror</div>
        <div class="wca-field"><label for="wca-rcs-cat">RCS category</label><input id="wca-rcs-cat" name="rcs_category" class="wca-input" type="text" maxlength="255" value="{{ $v('rcs_category') }}"></div>
    </div>
</section>

<section class="wca-card" aria-labelledby="wca-h-b">
    <h3 id="wca-h-b">Section B: Earlier extensions</h3>
    <input type="hidden" name="gsc_extension" value="0"><input type="hidden" name="vc_extension" value="0">
    <label class="wca-choice"><input type="checkbox" name="gsc_extension" value="1" @checked((bool) $v('gsc_extension', false))> I have already been given an extension through the Graduate Studies Committee (GSC)</label>
    <label class="wca-choice"><input type="checkbox" name="vc_extension" value="1" @checked((bool) $v('vc_extension', false))> I have already been given an extension through the Vice Chancellor (VC)</label>
</section>

<section class="wca-card" aria-labelledby="wca-h-c">
    <h3 id="wca-h-c">Section C: Publications</h3>
    <div id="wca-pubs">
        @foreach ($pubs as $i => $p)
            <div class="wca-pub-row">
                <input class="wca-input" type="text" name="publications[{{ $i }}][title]" maxlength="500" placeholder="Title" aria-label="Publication title" value="{{ $p['title'] ?? '' }}">
                <input class="wca-input" type="text" name="publications[{{ $i }}][venue]" maxlength="255" placeholder="Journal or conference" aria-label="Journal or conference" value="{{ $p['venue'] ?? '' }}">
                <input class="wca-input" type="text" name="publications[{{ $i }}][status]" maxlength="40" placeholder="Status" aria-label="Status" value="{{ $p['status'] ?? '' }}">
                <input class="wca-input" type="number" name="publications[{{ $i }}][year]" min="1950" max="2100" placeholder="Year" aria-label="Year" value="{{ $p['year'] ?? '' }}">
                <button type="button" class="wca-btn is-small" data-pub-remove>Remove</button>
            </div>
        @endforeach
    </div>
    <template id="wca-pub-template">
        <div class="wca-pub-row">
            <input class="wca-input" type="text" name="publications[__i__][title]" maxlength="500" placeholder="Title" aria-label="Publication title">
            <input class="wca-input" type="text" name="publications[__i__][venue]" maxlength="255" placeholder="Journal or conference" aria-label="Journal or conference">
            <input class="wca-input" type="text" name="publications[__i__][status]" maxlength="40" placeholder="Status" aria-label="Status">
            <input class="wca-input" type="number" name="publications[__i__][year]" min="1950" max="2100" placeholder="Year" aria-label="Year">
            <button type="button" class="wca-btn is-small" data-pub-remove>Remove</button>
        </div>
    </template>
    <button type="button" id="wca-pub-add" class="wca-btn is-small">Add a publication</button>
</section>

<section class="wca-card" aria-labelledby="wca-h-d">
    <h3 id="wca-h-d">Section D: Extension requested</h3>
    <div class="wca-field">
        <label for="wca-months">Months requested (1 to {{ $remaining }})</label>
        <select id="wca-months" name="requested_months" class="wca-select">
            <option value="">Choose the number of months</option>
            @for ($m = 1; $m <= $remaining; $m++)<option value="{{ $m }}" @selected((int) $v('requested_months') === $m)>{{ $m }} month(s)</option>@endfor
        </select>
        <p class="wca-muted wca-small">You have {{ $remaining }} month(s) of allowance left.</p>
        @error('requested_months')<p class="wca-error">{{ $message }}</p>@enderror
    </div>
    <div class="wca-field">
        <label for="wca-reason">Reason for the appeal</label>
        <textarea id="wca-reason" name="reason" class="wca-textarea" maxlength="4000">{{ $v('reason') }}</textarea>
        @error('reason')<p class="wca-error">{{ $message }}</p>@enderror
    </div>
    <input type="hidden" name="declaration" value="0">
    <label class="wca-choice"><input type="checkbox" name="declaration" value="1" @checked((bool) $appeal->declaration_accepted_at || old('declaration') === '1')> I declare that the information in this appeal is true and complete.</label>
    @error('declaration')<p class="wca-error">{{ $message }}</p>@enderror
</section>
