@extends('core::layouts.app')

@section('title', 'Open a Dismissal')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Dismissal for Exceeded Candidacy</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Opens a dismissal against a student whose RPD deadline passed without an
            approved extension. It is endorsed by the Dean of PGR, approved by Faculty,
            and the Registry sends the termination. You are the author, not an approver, so
            you will not see this again in a queue.
        </p>

        @if ($eligible->isEmpty())
            <div class="empty-state">
                <p>No candidacy is currently eligible for dismissal.</p>
                <p class="queue-meta">Only students past their deadline with no dismissal already open appear here.
                   <a href="{{ route('candidacies.index', ['filter' => 'overdue']) }}">See the overdue list.</a></p>
            </div>
        @else
            <form method="POST" action="{{ route('rpd-dismissal.store') }}" class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Student">
                <p class="fstep-hint">Only students past their RPD deadline with no dismissal already open are listed. The deadline they missed is shown against each name.</p>

                <label for="candidacy_id">Student</label>
                <select name="candidacy_id" id="candidacy_id" required
                        class="@error('candidacy_id') is-invalid @enderror">
                    <option value="">Select a student</option>
                    @foreach ($eligible as $candidacy)
                        <option value="{{ $candidacy->id }}" @selected(old('candidacy_id') == $candidacy->id)>
                            {{ $candidacy->student->name }}@if ($candidacy->student->matric_no) ({{ $candidacy->student->matric_no }})@endif
                            deadline {{ $candidacy->rpd_deadline->format('j M Y') }},
                            {{ abs($candidacy->daysRemaining()) }} days overdue
                        </option>
                    @endforeach
                </select>
                @error('candidacy_id') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Grounds">
                <p class="fstep-hint">Read by the Dean and by Faculty, and kept on the record after termination. State what was missed and what was already attempted.</p>

                <label for="grounds">Grounds for dismissal</label>
                <textarea name="grounds" id="grounds" rows="8" required
                          class="@error('grounds') is-invalid @enderror">{{ old('grounds') }}</textarea>
                @error('grounds') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <button type="submit">Open Dismissal</button>
            </form>
        @endif
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
