@extends('core::layouts.app')

@section('title', 'Nominate Examiner Panel')

@section('content')
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
    <div class="card card-wide">
        <h2>Examiner Panel Nomination — Appointment Letter</h2>
        <div class="card-divider"></div>

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
                <a href="{{ route('appointment-letter.examiners') }}"><b>Add an examiner to the list &rarr;</b></a>
            </div>
        @else
            <p class="queue-meta">
                Pick the full panel at once — at least one internal and one external
                examiner. CGS prepares an appointment letter and a thesis evaluation report
                for each of them, and the Dean approves the whole pack.
                Not on the list? <a href="{{ route('appointment-letter.examiners') }}">Add the examiner first</a>.
            </p>

            <p class="queue-meta">
                {{ $internal->count() }} internal and {{ $external->count() }} external examiners
                are free to take a panel today.
                @if ($unavailable->isNotEmpty())
                    Another {{ $unavailable->count() }}
                    {{ Str::plural('examiner', $unavailable->count()) }}
                    {{ $unavailable->count() === 1 ? 'is' : 'are' }} on an appointment and
                    {{ $unavailable->count() === 1 ? 'is' : 'are' }} not listed below — an examiner
                    is free again {{ \App\Modules\Jason\Models\PoolExaminer::COOLDOWN_MONTHS }}
                    months after the Dean appoints them.
                    <a href="{{ route('appointment-letter.examiners') }}">See who, and until when</a>.
                @endif
            </p>

            <form method="POST" action="{{ route('appointment-letter.store') }}">
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
                        <div class="examiner-row">
                            <label>Examiner <span class="row-number">{{ $i + 1 }}</span></label>
                            <div style="display: flex; gap: 8px; align-items: flex-start;">
                                <select name="examiners[{{ $i }}][pool_id]" required style="flex: 1;"
                                        class="@error("examiners.$i.pool_id") is-invalid @enderror">
                                    <option value="">— Select an examiner —</option>
                                    <optgroup label="Internal Examiners (UTP)">
                                        @foreach ($internal as $e)
                                            <option value="{{ $e->id }}" @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }} — {{ $e->institution }} ({{ $e->expertise }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="External Examiners">
                                        @foreach ($external as $e)
                                            <option value="{{ $e->id }}" @selected(($row['pool_id'] ?? '') == $e->id)>
                                                {{ $e->name }} — {{ $e->institution }} ({{ $e->expertise }})
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
                row.querySelector('select').name = 'examiners[' + i + '][pool_id]';
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
    })();
</script>
@endpush
