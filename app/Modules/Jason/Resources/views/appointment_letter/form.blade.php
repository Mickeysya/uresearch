@extends('core::layouts.app')

@section('title', 'Nominate Examiner Panel')

@section('content')
@push('head')
    <style>
        .examiner-row-filters,
        .examiner-row-picker { display: flex; gap: 8px; align-items: flex-start; }
        .examiner-row-filters { margin-bottom: 6px; }
        .examiner-row-filters .examiner-type { flex: 0 0 170px; }
        .examiner-row-filters .examiner-filter { flex: 1; min-width: 0; }
        .examiner-row-picker .examiner-select { flex: 1; min-width: 0; }
        .examiner-row-picker .remove-row { flex: 0 0 auto; padding: 8px 12px; }
        .examiner-row + .examiner-row { margin-top: 18px; }
        @media (max-width: 640px) {
            .examiner-row-filters { flex-wrap: wrap; }
            .examiner-row-filters .examiner-type { flex: 1 0 100%; }
        }
    </style>
@endpush
@php
    // Repopulate after a validation failure, otherwise start with one
    // internal and one external slot -- the smallest panel that is valid.
    $rows = old('examiners', [['pool_id' => ''], ['pool_id' => '']]);

    // $pool holds only the examiners who can actually be picked today;
    // $unavailable is everyone on a cooldown, counted but not listed.
    $internal = $pool->filter(fn ($e) => $e->isInternal());
    $external = $pool->reject(fn ($e) => $e->isInternal());
    $busyInternal = $unavailable->filter(fn ($e) => $e->isInternal());
    $busyExternal = $unavailable->reject(fn ($e) => $e->isInternal());
@endphp
<div class="card-container-inline">
    <x-core::page-header
        title="Examiner Panel Nomination: Appointment Letter" />

    <div class="card card-wide">
        @if ($candidates->isEmpty())
            <div class="empty-state">
                There are no candidates in your department yet.<br>
                CGS assigns students to a department before nominations can be filed.
            </div>
        @elseif ($internal->isEmpty() || $external->isEmpty())
            @php
                // Two different problems with the same symptom: nobody of that
                // kind is on the list at all, or everybody of that kind is on
                // an appointment. They need different answers.
                $missing = collect([
                    'internal' => ['available' => $internal, 'busy' => $busyInternal],
                    'external' => ['available' => $external, 'busy' => $busyExternal],
                ])->filter(fn ($side) => $side['available']->isEmpty());
            @endphp
            <div class="empty-state">
                @foreach ($missing as $type => $side)
                    @if ($side['busy']->isEmpty())
                        <p>There is no {{ $type }} examiner on the list, and a panel needs at least one.</p>
                    @elseif ($side['busy']->count() === 1)
                        <p>
                            The only {{ $type }} examiner on the list is on an appointment until
                            <b>{{ $side['busy']->first()->availableFrom()->format('j M Y') }}</b>.
                        </p>
                    @else
                        <p>
                            All {{ $side['busy']->count() }} {{ $type }} examiners on the list are on an
                            appointment. The earliest comes free on
                            <b>{{ $side['busy']->map->availableFrom()->min()->format('j M Y') }}</b>.
                        </p>
                    @endif
                @endforeach
                <a href="{{ route('appointment-letter.examiners.create', ['return' => 'nominate']) }}"
                   class="btn">Add an examiner</a>
            </div>
        @else
            <p class="queue-meta">
                Pick the full panel at once, with at least one internal and one external
                examiner. CGS prepares an appointment letter and a thesis evaluation report
                for each of them, and the Dean approves the whole pack.
                Not on the list?
                <a href="{{ route('appointment-letter.examiners.create', ['return' => 'nominate']) }}"
                   data-keep-panel>Add the examiner first</a>. You come back here
                with the panel as you left it.
            </p>

            <p class="queue-meta">
                {{ $internal->count() }} internal and {{ $external->count() }} external examiners
                are free to take a panel today.
                @if ($unavailable->isNotEmpty())
                    Another {{ $unavailable->count() }}
                    {{ Str::plural('examiner', $unavailable->count()) }}
                    {{ $unavailable->count() === 1 ? 'is' : 'are' }} on an appointment and
                    {{ $unavailable->count() === 1 ? 'is' : 'are' }} not listed below. An examiner
                    is free again {{ \App\Modules\Jason\Models\PoolExaminer::COOLDOWN_MONTHS }}
                    months after the Dean appoints them.
                    <a href="{{ route('appointment-letter.examiners') }}">See who, and until when</a>.
                @endif
            </p>

            <form method="POST" action="{{ route('appointment-letter.store') }}"
                  class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Candidate">
                <p class="fstep-hint">Who the panel is being appointed for.</p>

                <label for="student_id">Candidate</label>
                <select name="student_id" id="student_id" required
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">Select the candidate</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected(old('student_id') == $candidate->id)>
                            {{ $candidate->name }} @if ($candidate->matric_no)({{ $candidate->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Examiner panel">
                <p class="fstep-hint">At least one internal and one external. Add as
                   many rows as the panel needs.</p>

                @error('examiners') <p class="field-error">{{ $message }}</p> @enderror

                <div id="examiners">
                    @foreach ($rows as $i => $row)
                        @php
                            // Which kind this row is picking. Follows whatever is
                            // already chosen after a validation failure; otherwise the
                            // first two rows are the minimum valid panel, one of each.
                            $chosen = $pool->firstWhere('id', $row['pool_id'] ?? null);
                            $rowType = $chosen?->examiner_type ?? ($i === 1 ? 'external' : 'internal');
                        @endphp
                        <div class="examiner-row">
                            <label for="examiner-{{ $i }}">Examiner <span class="row-number">{{ $i + 1 }}</span></label>

                            {{-- Kind first, then a filter, then the names. With a
                                 hundred examiners on the list, opening one long
                                 dropdown and scrolling for the group you want is not a
                                 way to pick anybody. --}}
                            <div class="examiner-row-filters">
                                <select class="examiner-type" aria-label="Examiner {{ $i + 1 }} type">
                                    <option value="internal" @selected($rowType === 'internal')>Internal (UTP)</option>
                                    <option value="external" @selected($rowType === 'external')>External</option>
                                </select>
                                <input type="search" class="examiner-filter" autocomplete="off"
                                       aria-label="Filter examiner {{ $i + 1 }}"
                                       placeholder="Filter by name, institution or expertise">
                            </div>

                            <div class="examiner-row-picker">
                                <select name="examiners[{{ $i }}][pool_id]" id="examiner-{{ $i }}" required
                                        class="examiner-select @error("examiners.$i.pool_id") is-invalid @enderror">
                                    <option value="">Select an examiner</option>
                                    <optgroup label="Internal Examiners (UTP)" data-type="internal">
                                        @foreach ($internal as $e)
                                            <option value="{{ $e->id }}" data-type="internal"
                                                    @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }}, {{ $e->institution }} ({{ $e->expertise }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="External Examiners" data-type="external">
                                        @foreach ($external as $e)
                                            <option value="{{ $e->id }}" data-type="external"
                                                    @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }}, {{ $e->institution }} ({{ $e->expertise }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                                <button type="button" class="remove-row"
                                        @if (count($rows) <= 2) hidden @endif>Remove</button>
                            </div>

                            <p class="queue-meta examiner-count" style="margin: 4px 0 0;"></p>
                            @error("examiners.$i.pool_id") <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <p class="queue-meta">
                    The appointment pack is emailed to each examiner's address from the list once
                    the Dean approves.
                </p>

                <button type="button" id="add-examiner">+ Add another examiner</button>
                </fieldset>

                <button type="submit">Submit Nomination</button>
            </form>

            @include('core::partials.form-stepper')
        @endif
    </div>
</div>

@endsection

@push('scripts')
{{-- @cspNonce is not optional: script-src is 'self' plus this request's nonce,
     with no unsafe-inline, so without it the browser refuses to run this and
     Add/Remove silently do nothing. --}}
<script @cspNonce>
    (function () {
        const list = document.getElementById('examiners');
        const add = document.getElementById('add-examiner');
        if (!list || !add) return;

        // Narrows a row's dropdown to the kind it is picking and to whatever
        // has been typed in its filter box. The whole list is in the markup;
        // this only hides what does not match, so nothing depends on a
        // round-trip and the posted value is still a plain pool_id.
        function applyFilter(row) {
            const type = row.querySelector('.examiner-type').value;
            const needle = row.querySelector('.examiner-filter').value.trim().toLowerCase();
            const select = row.querySelector('.examiner-select');
            let shown = 0;

            row.querySelectorAll('optgroup').forEach((group) => {
                const wanted = group.dataset.type === type;
                group.hidden = ! wanted;
                // Safari ignores hidden on an optgroup; disabled it obeys.
                group.disabled = ! wanted;

                group.querySelectorAll('option').forEach((option) => {
                    const matches = wanted
                        && (needle === '' || option.textContent.toLowerCase().includes(needle));
                    option.hidden = ! matches;
                    option.disabled = ! matches;
                    if (matches) shown++;
                });
            });

            // A name that has just been filtered out must not stay selected.
            const selected = select.selectedOptions[0];
            if (selected && selected.value !== '' && selected.hidden) {
                select.value = '';
            }

            const count = row.querySelector('.examiner-count');
            if (needle === '') {
                count.textContent = shown + ' ' + type + ' examiner' + (shown === 1 ? '' : 's')
                    + ' available to pick.';
            } else {
                count.textContent = shown === 0
                    ? 'No ' + type + ' examiner matches “' + needle + '”.'
                    : shown + ' of the ' + type + ' examiners match “' + needle + '”.';
            }
        }

        function renumber() {
            const rows = list.querySelectorAll('.examiner-row');
            rows.forEach((row, i) => {
                row.querySelector('.row-number').textContent = i + 1;
                row.querySelector('.examiner-select').name = 'examiners[' + i + '][pool_id]';
                row.querySelector('.examiner-select').id = 'examiner-' + i;
                row.querySelector('label').setAttribute('for', 'examiner-' + i);
                row.querySelector('.examiner-type').setAttribute('aria-label', 'Examiner ' + (i + 1) + ' type');
                row.querySelector('.examiner-filter').setAttribute('aria-label', 'Filter examiner ' + (i + 1));
                // The two default slots stay; anything beyond can be removed.
                row.querySelector('.remove-row').hidden = rows.length <= 2;
            });
        }

        add.addEventListener('click', () => {
            const clone = list.querySelector('.examiner-row').cloneNode(true);
            const select = clone.querySelector('.examiner-select');
            select.value = '';
            select.classList.remove('is-invalid');
            clone.querySelector('.examiner-filter').value = '';
            clone.querySelectorAll('.field-error').forEach((el) => el.remove());
            list.appendChild(clone);
            renumber();
            applyFilter(clone);
        });

        list.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row')) {
                e.target.closest('.examiner-row').remove();
                renumber();
            }
        });

        list.addEventListener('input', (e) => {
            if (e.target.classList.contains('examiner-filter')) {
                applyFilter(e.target.closest('.examiner-row'));
            }
        });

        list.addEventListener('change', (e) => {
            if (e.target.classList.contains('examiner-type')) {
                applyFilter(e.target.closest('.examiner-row'));
            }
        });

        renumber();
        list.querySelectorAll('.examiner-row').forEach(applyFilter);

        /* ---- surviving a trip to the Examiner List -------------------
           "Add the examiner first" is a real navigation, so without this
           the candidate and every row already picked are gone by the time
           you get back -- which is what made two sidebar entries feel like
           one job done twice. Saved on the way out, restored once on the
           way in, then dropped.

           sessionStorage rather than the server: a half-filled panel is
           not worth a drafts table, a session key or a resume route, and
           it is the same call the wizard itself makes. Wrapped because
           storage throws outright in a locked-down browser, and losing
           Add/Remove with it would be a worse bug than the one this
           fixes. */
        const KEY = 'appointment-letter.panel';
        const form = list.closest('form');

        function readPanel() {
            return {
                student_id: form.student_id.value,
                examiners: [...list.querySelectorAll('.examiner-select')].map(s => s.value),
            };
        }

        document.querySelectorAll('[data-keep-panel]').forEach(link => {
            link.addEventListener('click', () => {
                try {
                    sessionStorage.setItem(KEY, JSON.stringify(readPanel()));
                } catch (e) { /* no storage: the trip just costs the panel, as before */ }
            });
        });

        try {
            const saved = sessionStorage.getItem(KEY);

            if (saved) {
                // Once only. Coming back a second time should start clean.
                sessionStorage.removeItem(KEY);

                const panel = JSON.parse(saved);
                const picked = Array.isArray(panel.examiners) ? panel.examiners : [];

                // An option that has since been removed from the list sets
                // the select back to blank, which is the honest answer.
                form.student_id.value = panel.student_id || '';

                while (list.querySelectorAll('.examiner-row').length < picked.length) {
                    add.click();
                }

                list.querySelectorAll('.examiner-row').forEach((row, i) => {
                    if (! picked[i]) return;
                    const select = row.querySelector('.examiner-select');
                    const option = select.querySelector('option[value="' + picked[i] + '"]');
                    if (! option) return;
                    // The row's kind has to match the restored person, or the
                    // filter would hide them again the moment it re-ran.
                    row.querySelector('.examiner-type').value = option.dataset.type;
                    row.querySelector('.examiner-filter').value = '';
                    applyFilter(row);
                    select.value = picked[i];
                });
            }
        } catch (e) { /* stale or unreadable: leave the form as rendered */ }
    })();
</script>
@endpush
