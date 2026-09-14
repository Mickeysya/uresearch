@extends('core::layouts.app')

@section('title', 'Nominate Examiner Panel')

@section('content')
@php
    // Repopulate after a validation failure, otherwise start with one
    // internal and one external slot -- the smallest panel that is valid.
    $rows = old('examiners', [
        ['examiner_type' => 'internal'],
        ['examiner_type' => 'external'],
    ]);
@endphp
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner Panel Nomination — Appointment Letter</h2>
        <div class="card-divider"></div>

        @if ($candidates->isEmpty())
            <div class="empty-state">
                There are no candidates in your department yet.<br>
                CGS assigns students to a department before nominations can be filed.
            </div>
        @else
            <p class="queue-meta">
                Nominate the full panel at once — at least one internal and one external
                examiner. CGS prepares an appointment letter and a thesis evaluation report
                for each of them, and the Dean approves the whole pack.
            </p>

            <form method="POST" action="{{ route('appointment-letter.store') }}" id="panel-form">
                @csrf

                <label for="student_id">Candidate</label>
                <select name="student_id" id="student_id" required
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">— Select the candidate —</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected(old('student_id') == $candidate->id)>
                            {{ $candidate->name }} @if ($candidate->matric_no)({{ $candidate->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

                @error('examiners') <p class="field-error">{{ $message }}</p> @enderror

                <div id="examiners">
                    @foreach ($rows as $i => $row)
                        <fieldset class="app-item examiner-row" style="margin: 16px 0; border: 1px solid var(--border, #ddd); padding: 14px;">
                            <legend style="font-weight: 600; padding: 0 6px;">Examiner <span class="row-number">{{ $i + 1 }}</span></legend>

                            <label>Type</label>
                            <select name="examiners[{{ $i }}][examiner_type]" required>
                                <option value="internal" @selected(($row['examiner_type'] ?? '') === 'internal')>Internal Examiner (UTP)</option>
                                <option value="external" @selected(($row['examiner_type'] ?? '') === 'external')>External Examiner</option>
                            </select>
                            @error("examiners.$i.examiner_type") <p class="field-error">{{ $message }}</p> @enderror

                            <label>Examiner Name</label>
                            <input type="text" name="examiners[{{ $i }}][examiner_name]" required
                                   value="{{ $row['examiner_name'] ?? '' }}"
                                   class="@error("examiners.$i.examiner_name") is-invalid @enderror">
                            @error("examiners.$i.examiner_name") <p class="field-error">{{ $message }}</p> @enderror

                            <label>Institution / Faculty</label>
                            <input type="text" name="examiners[{{ $i }}][examiner_institution]" required
                                   value="{{ $row['examiner_institution'] ?? '' }}"
                                   class="@error("examiners.$i.examiner_institution") is-invalid @enderror">
                            @error("examiners.$i.examiner_institution") <p class="field-error">{{ $message }}</p> @enderror

                            <label>Examiner Email</label>
                            <input type="email" name="examiners[{{ $i }}][examiner_email]" required
                                   value="{{ $row['examiner_email'] ?? '' }}"
                                   class="@error("examiners.$i.examiner_email") is-invalid @enderror">
                            @error("examiners.$i.examiner_email") <p class="field-error">{{ $message }}</p> @enderror

                            <label>Area of Expertise</label>
                            <input type="text" name="examiners[{{ $i }}][examiner_expertise]" required
                                   value="{{ $row['examiner_expertise'] ?? '' }}"
                                   class="@error("examiners.$i.examiner_expertise") is-invalid @enderror">
                            @error("examiners.$i.examiner_expertise") <p class="field-error">{{ $message }}</p> @enderror

                            <button type="button" class="remove-row" style="margin-top: 8px;"
                                    @if (count($rows) <= 2) hidden @endif>Remove this examiner</button>
                        </fieldset>
                    @endforeach
                </div>

                <p class="queue-meta">
                    The appointment pack is emailed to each examiner's address once the Dean
                    approves — double-check them before submitting.
                </p>

                <button type="button" id="add-examiner">+ Add another examiner</button>
                <button type="submit">Submit Nomination</button>
            </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const list = document.getElementById('examiners');
        const add = document.getElementById('add-examiner');
        if (!list || !add) return;

        function renumber() {
            const rows = list.querySelectorAll('.examiner-row');
            rows.forEach((row, i) => {
                row.querySelector('.row-number').textContent = i + 1;
                row.querySelectorAll('[name]').forEach(el => {
                    el.name = el.name.replace(/examiners\[\d+\]/, 'examiners[' + i + ']');
                });
                // The two default slots stay; anything beyond can be removed.
                row.querySelector('.remove-row').hidden = rows.length <= 2;
            });
        }

        add.addEventListener('click', () => {
            const template = list.querySelector('.examiner-row');
            const clone = template.cloneNode(true);
            clone.querySelectorAll('input').forEach(el => el.value = '');
            clone.querySelectorAll('.field-error').forEach(el => el.remove());
            clone.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            clone.querySelector('select').value = 'external';
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
    })();
</script>
@endpush
