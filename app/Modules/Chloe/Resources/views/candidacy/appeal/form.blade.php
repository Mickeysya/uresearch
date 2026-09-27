@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal')

@section('content')
@include('chloe::partials.styles')

<div class="card-container-inline">
    <x-core::page-header title="Study Candidacy Appeal"
                         subtitle="Ask for more time on your candidacy. It goes to your supervisor, the Programme Chair, CGS and finally the Dean of PGR." />

    <div class="card card-wide">
        {{-- The facts being appealed against, stated before the form asks
             anything, the same way the RPD appeal does. --}}
        <dl class="rpd-facts">
            <div>
                <dt>Candidacy expires</dt>
                <dd>{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</dd>
            </div>
            <div>
                <dt>Extension left</dt>
                <dd>{{ $candidacy->remainingAppealMonths() }} of {{ \App\Modules\Chloe\Models\StudyCandidacy::MAX_APPEAL_MONTHS }} months</dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('candidacy-appeal.store') }}" enctype="multipart/form-data"
              class="app-form" id="appeal-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Supervisor">
                <p class="fstep-hint">Your appeal goes to this supervisor first.</p>

                <label for="supervisor_id">Supervisor</label>
                <select name="supervisor_id" id="supervisor_id" required class="@error('supervisor_id') is-invalid @enderror">
                    <option value="">Select your supervisor</option>
                    @foreach ($supervisors as $supervisor)
                        <option value="{{ $supervisor->id }}" @selected(old('supervisor_id') == $supervisor->id)>{{ $supervisor->name }}</option>
                    @endforeach
                </select>
                @error('supervisor_id') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            @include('chloe::candidacy.appeal._fields', ['candidacy' => $candidacy])

            <fieldset class="fstep" data-label="Declaration">
                <p class="fstep-hint">Confirm the information is accurate before you submit.</p>

                <div class="checkbox-row">
                    <input type="checkbox" name="disclaimer" id="disclaimer" value="1" required @checked(old('disclaimer'))>
                    <label for="disclaimer">
                        All of the information I have provided is valid and accurate at the time of submission.
                        I understand that any deceit or untruth may lead to the application being discarded.
                    </label>
                </div>
                @error('disclaimer') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Appeal</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
