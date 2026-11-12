@extends('core::layouts.app')

@section('title', 'DAC Compilation')

{{--
    The Academic Executive's DAC Compilation (UAT 11 Nov 2026): her
    department's nominations waiting at stage 1, the compiled list for the
    meeting, and the meeting's outcome. See ExaminerDacController.
    Three forms share one set of checkboxes through the form attribute
    (nested forms are illegal).
--}}
@push('head')
    <style>
        /* The compiled list never breaks out of the card: fixed columns, wrapping cells, scroll on narrow screens. */
        .dac-scroll { overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch; }
        .dac-table { table-layout: fixed; width: 100%; min-width: 880px; }
        .dac-table th, .dac-table td { vertical-align: top; white-space: normal; overflow-wrap: break-word; }
        .dac-table td { overflow-wrap: anywhere; }
        .dac-seats { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
        .dac-seat { font-weight: 600; color: var(--text-grey); }
        .dac-flag-group + .dac-flag-group { margin-top: 8px; }
    </style>
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="DAC Compilation"
        :subtitle="$nominations->count().' '.Str::plural('nomination', $nominations->count()).' from '.($department ?: 'your department').' waiting for the DAC. Only DAC-endorsed nominations go on to CGS.'" />

    @if ($nominations->isEmpty())
        <div class="empty-state"><p>No nomination is waiting for the DAC.</p></div>
    @else
        <form method="GET" action="{{ route('examiner-nomination.dac.export') }}" id="dac-export"></form>
        <form method="POST" action="{{ route('examiner-nomination.dac.endorse') }}" id="dac-endorse" enctype="multipart/form-data">@csrf</form>
        <form method="POST" action="{{ route('examiner-nomination.dac.not-endorsed') }}" id="dac-reject">@csrf</form>

        <div class="card card-wide">
            <p class="fstep-hint">Tick the nominations to act on. The download takes the ticked ones, or all of them when none is ticked.</p>
            <div class="dac-scroll">
            <table class="table dac-table">
                <colgroup>
                    <col style="width: 40px"><col style="width: 12%"><col style="width: 10%"><col style="width: 14%"><col style="width: 9%"><col style="width: 30%"><col style="width: 25%">
                </colgroup>
                <thead>
                    <tr>
                        <th><input type="checkbox" data-dac-all aria-label="Select all"></th>
                        <th>Candidate</th><th>Programme</th><th>Thesis title</th><th>Supervisor</th><th>Panel (four seats)</th><th>Flags</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($nominations as $n)
                        @php($student = $n->application?->student)
                        <tr>
                            <td>
                                @foreach (['dac-export', 'dac-endorse', 'dac-reject'] as $form)
                                    <input type="checkbox" name="ids[]" value="{{ $n->application_id }}" form="{{ $form }}" data-dac-check="{{ $n->application_id }}"
                                        @if ($form !== 'dac-export') hidden aria-hidden="true" tabindex="-1" @else aria-label="Select #{{ $n->application_id }}" @endif
                                        @checked(in_array($n->application_id, (array) old('ids', [])))>
                                @endforeach
                            </td>
                            <td>#{{ $n->application_id }} {{ $student?->name }}<br><span class="fstep-hint">{{ $student?->matric_no }}</span></td>
                            <td>{{ $student?->programme }}</td>
                            <td>{{ $n->thesis_title }}</td>
                            <td>{{ $n->application?->submittedBy?->name ?? $student?->supervisor?->name }}</td>
                            <td>
                                <ul class="dac-seats">
                                    @foreach ($n->panel() as $seat => $examiner)
                                        <li><span class="dac-seat">{{ $seat }}:</span> {{ $examiner?->name ?? 'Not named' }}@if ($examiner) <span class="fstep-hint">· {{ $examiner->institution ?: $examiner->department }}</span>@endif</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td>
                                @php($byExaminer = collect($coiFlags[$n->application_id] ?? [])->filter())
                                @forelse ($byExaminer as $examinerId => $flags)
                                    <div class="dac-flag-group">
                                        <span class="fstep-hint">{{ collect($n->panel())->filter()->firstWhere('id', $examinerId)?->name }}</span>
                                        @include('hani::examiner_nomination._coi', ['flags' => $flags])
                                    </div>
                                @empty
                                    <span class="fstep-hint">None</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @error('ids') <p class="field-error">{{ $message }}</p> @enderror

            <h3>Download compiled list</h3>
            <button type="submit" form="dac-export" name="format" value="xlsx" class="btn-secondary">Download Excel (.xlsx)</button>
            <button type="submit" form="dac-export" name="format" value="pdf" class="btn-secondary">Download PDF</button>
        </div>

        <div class="card card-wide">
            <h3>Record DAC approval</h3>
            <label for="meeting_date">DAC meeting date</label>
            <input type="date" id="meeting_date" name="meeting_date" form="dac-endorse" required max="{{ now()->toDateString() }}" value="{{ old('meeting_date') }}">
            @error('meeting_date') <p class="field-error">{{ $message }}</p> @enderror
            <label for="reference">Meeting reference (optional)</label>
            <input type="text" id="reference" name="reference" form="dac-endorse" maxlength="150" value="{{ old('reference') }}" placeholder="e.g. DAC/2026/11">
            <label for="minutes">Minutes (optional, PDF)</label>
            <input type="file" id="minutes" name="minutes" form="dac-endorse" accept="application/pdf,.pdf">
            @error('minutes') <p class="field-error">{{ $message }}</p> @enderror
            <p class="fstep-hint">Each ticked nomination is approved on its own, with the DAC date in its history, and goes to the Senior Executive CGS.</p>
            <button type="submit" form="dac-endorse">Record DAC approval for the ticked nominations</button>
        </div>

        <div class="card card-wide">
            <h3>Not endorsed by DAC</h3>
            <label for="reason">Reason (sent to the supervisor and the student)</label>
            <textarea id="reason" name="reason" form="dac-reject" rows="3" maxlength="2000">{{ old('reason') }}</textarea>
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" form="dac-reject" class="btn-reject">Record not endorsed for the ticked nominations</button>
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <div class="card card-wide">
            <h3>Recent DAC records</h3>
            <ul>
                @foreach ($recent as $record)
                    <li>
                        #{{ $record->application_id }} {{ $record->application?->student?->name }}:
                        {{ $record->outcome === 'endorsed' ? $record->label() : 'Not endorsed: '.$record->reason }}
                        @if ($record->minutes_path) · <a href="{{ route('examiner-nomination.dac.minutes', $record) }}" target="_blank" rel="noopener">Minutes</a> @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

</div>

    <script @cspNonce>
        (function () {
            // One visible checkbox per row; its hidden twins for the two POST forms follow it.
            var visible = document.querySelectorAll('input[form="dac-export"][data-dac-check]');
            function mirror(box) {
                document.querySelectorAll('[data-dac-check="' + box.value + '"]').forEach(function (twin) { twin.checked = box.checked; });
            }
            visible.forEach(function (box) { box.addEventListener('change', function () { mirror(box); }); mirror(box); });
            var all = document.querySelector('[data-dac-all]');
            if (all) all.addEventListener('change', function () { visible.forEach(function (box) { box.checked = all.checked; mirror(box); }); });
        })();
    </script>
@endsection
