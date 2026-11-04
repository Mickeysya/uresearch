@extends('core::layouts.app')

@section('title', 'Re-viva Outcomes')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Re-viva Outcomes"
        subtitle="Consolidation is complete: confirm the outcome level. The system suggests it from the two re-evaluations. Levels 1 to 3 set the final-thesis deadline; level 4 means an oral examination is required, and CGS logs the next round; level 5 is final." />

    <div class="card card-wide">
        @forelse ($details as $detail)
            @php
                $suggestion = $suggestions[$detail->id];
                $finalMonths = config('hani.reviva.final_thesis_months');
            @endphp
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>{{ $detail->application->student->name }}</b>: re-viva round {{ $detail->cycle_number }}</p>
                    <span class="fstep-hint">Application #{{ $detail->application_id }}</span>
                </div>
                @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($detail)])

                <p>Resubmitted: {{ $detail->resubmission_at->format('j M Y') }}</p>

                @include('hani::re_viva._evaluations', ['detail' => $detail])
                @include('hani::re_viva._staff_documents', ['detail' => $detail])

                <div class="reviva-suggestion">
                    @if ($suggestion['level'])
                        <p><span class="status-badge approved">Suggested</span> <b>{{ \App\Modules\Hani\Models\ReVivaDetail::OUTCOME_LABELS[$suggestion['level']] }}</b></p>
                    @else
                        <p><span class="status-badge pending">Consolidation needed</span></p>
                    @endif
                    <p class="fstep-hint">{{ $suggestion['text'] }}</p>
                </div>

                <form method="POST" action="{{ route('reviva.outcomes.record', $detail) }}">
                    @csrf

                    <label for="outcome_level-{{ $detail->id }}">Outcome level</label>
                    <select name="outcome_level" id="outcome_level-{{ $detail->id }}" required>
                        <option value="">Select</option>
                        @foreach (\App\Modules\Hani\Models\ReVivaDetail::OUTCOME_LABELS as $level => $label)
                            <option value="{{ $level }}" @selected((int) old('outcome_level', $suggestion['level']) === $level)>
                                {{ $label }}@if (isset($finalMonths[$level])): due {{ now()->addMonthsNoOverflow($finalMonths[$level])->format('j M Y') }}@endif
                            </option>
                        @endforeach
                    </select>
                    @error('outcome_level') <p class="field-error">{{ $message }}</p> @enderror

                    <label for="outcome_remarks-{{ $detail->id }}">Remarks
                        <span style="color: var(--text-grey)">({{ $suggestion['level'] ? 'required only if you choose a different level' : 'required: how the consolidation reached the level' }})</span></label>
                    <textarea id="outcome_remarks-{{ $detail->id }}" name="outcome_remarks" rows="2" @required(! $suggestion['level'])>{{ old('outcome_remarks') }}</textarea>
                    @error('outcome_remarks') <p class="field-error">{{ $message }}</p> @enderror

                    <p class="fstep-hint">The student and supervisor are told the level and the next step, never an examiner's name or individual recommendation.</p>
                    <button type="submit">Confirm Outcome</button>
                </form>
            </div>
        @empty
            <div class="empty-state">No consolidated rounds awaiting an outcome.</div>
        @endforelse
    </div>
</div>
@endsection
