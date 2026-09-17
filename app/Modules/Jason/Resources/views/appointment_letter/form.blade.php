@extends('core::layouts.app')

@section('title', 'Nominate Examiner Panel')

@section('content')
@php
    // Repopulate after a validation failure, otherwise start with one
    // internal and one external slot -- the smallest panel that is valid.
    $rows = old('examiners', [['pool_id' => ''], ['pool_id' => '']]);
    $internal = $pool->filter(fn ($e) => $e->isInternal());
    $external = $pool->reject(fn ($e) => $e->isInternal());
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
            <div class="empty-state">
                <p>The examiner list needs at least one internal and one external
                   examiner before a panel can be nominated.</p>
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
                        <div class="examiner-row">
                            <label for="examiner-{{ $i }}">Examiner <span class="row-number">{{ $i + 1 }}</span></label>
                            <div style="display: flex; gap: 8px; align-items: flex-start;">
                                <select name="examiners[{{ $i }}][pool_id]" id="examiner-{{ $i }}" required style="flex: 1;"
                                        class="@error("examiners.$i.pool_id") is-invalid @enderror">
                                    <option value="">Select an examiner</option>
                                    <optgroup label="Internal Examiners (UTP)">
                                        @foreach ($internal as $e)
                                            <option value="{{ $e->id }}" @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }}, {{ $e->institution }} ({{ $e->expertise }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="External Examiners">
                                        @foreach ($external as $e)
                                            <option value="{{ $e->id }}" @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }}, {{ $e->institution }} ({{ $e->expertise }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                                <button type="button" class="remove-row" style="padding: 8px 12px;"
                                        @if (count($rows) <= 2) hidden @endif>Remove</button>
                            </div>
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

        function renumber() {
            const rows = list.querySelectorAll('.examiner-row');
            rows.forEach((row, i) => {
                row.querySelector('.row-number').textContent = i + 1;
                row.querySelector('select').name = 'examiners[' + i + '][pool_id]';
                row.querySelector('select').id = 'examiner-' + i;
                row.querySelector('label').setAttribute('for', 'examiner-' + i);
                // The two default slots stay; anything beyond can be removed.
                row.querySelector('.remove-row').hidden = rows.length <= 2;
            });
        }

        add.addEventListener('click', () => {
            const clone = list.querySelector('.examiner-row').cloneNode(true);
            clone.querySelector('select').value = '';
            clone.querySelectorAll('.field-error').forEach(el => el.remove());
            clone.querySelector('select').classList.remove('is-invalid');
            list.appendChild(clone);
            renumber();
        });

        list.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row')) {
                e.target.closest('.examiner-row').remove();
                renumber();
            }
        });

        renumber();

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
                examiners: [...list.querySelectorAll('select')].map(s => s.value),
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

                list.querySelectorAll('select').forEach((select, i) => {
                    if (picked[i]) select.value = picked[i];
                });
            }
        } catch (e) { /* stale or unreadable: leave the form as rendered */ }
    })();
</script>
@endpush
