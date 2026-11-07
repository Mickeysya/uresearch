{{-- One nomination on a Conflict Detection row. Expects $entry and $row; see
     ConflictDetectionController::entry(). --}}
@php
    $application = $entry['nomination']->application;
    $request = $entry['request'];
    $formKey = $entry['nomination']->id.'|'.$entry['seat'];
    $returns = in_array($application->current_stage, \App\Modules\Hani\Http\Controllers\ExaminerNominationController::RETURNING_STAGES, true);
@endphp

<div class="cd-entry">
    @if ($entry['cardUrl'])
        <a href="{{ $entry['cardUrl'] }}" data-region-swap-ignore>#{{ $application->id }}</a>
    @else
        #{{ $application->id }}
    @endif
    <span class="cd-meta">{{ $entry['department'] }} · {{ \App\Modules\Hani\Models\ExaminerNomination::SLOTS[$entry['seat']] ?? '' }}</span>

    @if ($entry['released'])
        <span class="status-badge approved">{{ $row['conflicted'] && $row['soleReleased'] ? 'Released: keeps the examiner' : 'Released' }}</span>
    @else
        <span class="status-badge pending">{{ $entry['stageLabel'] }}</span>
    @endif

    @if ($row['conflicted'])
        @if ($entry['manageUrl'])
            <a href="{{ $entry['manageUrl'] }}" class="cd-note" data-region-swap-ignore>Manage panel → Replace</a>
        @endif

        @if ($request)
            <span class="cd-note">
                Substitution requested {{ $request->created_at?->format('j M Y') }}
                @if ($entry['own'])
                    by {{ $request->requestedBy?->name ?? 'CGS' }}: {{ $request->reason }}
                @endif
            </span>
            @if ($entry['own'] && auth()->user()->role === \App\Modules\Core\Support\Role::ACADEMIC_EXEC)
                <a href="{{ route('examiner-substitution.show', $request) }}" class="cd-note" data-region-swap-ignore>Replace this seat</a>
            @endif
        @elseif ($entry['canAsk'])
            <details @if (old('form_key') === $formKey) open @endif>
                <summary class="btn-secondary">Ask department to substitute</summary>
                <form method="POST" action="{{ route('examiner-substitution.store', $entry['nomination']) }}">
                    @csrf
                    <input type="hidden" name="seat" value="{{ $entry['seat'] }}">
                    <input type="hidden" name="form_key" value="{{ $formKey }}">
                    <label for="reason-{{ $formKey }}">Why should they replace {{ $row['examiner']->name }}?</label>
                    <textarea name="reason" id="reason-{{ $formKey }}" rows="2" required maxlength="2000"
                              class="@if (old('form_key') === $formKey) @error('reason') is-invalid @enderror @endif">{{ old('form_key') === $formKey ? old('reason') : '' }}</textarea>
                    @if (old('form_key') === $formKey) @error('reason') <p class="field-error">{{ $message }}</p> @enderror @endif
                    <span class="cd-note">
                        @if ($returns)
                            This also sends the list back to the department. Their Academic Executive and the supervisor get your reason; the candidate does not.
                        @else
                            The list stays with you. Their Academic Executive and the supervisor get your reason and a link to replace the seat.
                        @endif
                    </span>
                    <button type="submit" class="btn-reject">Ask department to substitute</button>
                </form>
            </details>
        @elseif (! $entry['released'] && $entry['stageLabel'])
            <span class="cd-note">
                @if ($returns)
                    With the {{ $entry['stageLabel'] }}, who can send it back to the department.
                @elseif ($application->current_stage === 'academic_exec')
                    With the department. A substitution can be asked for once CGS is compiling it.
                @else
                    Being finalised. Once released, its panel can be changed under Manage panel.
                @endif
            </span>
        @endif
    @endif
</div>
