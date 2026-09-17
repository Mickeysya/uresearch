@extends('core::layouts.app')

@section('title', 'Add an Examiner')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Add an Examiner"
        subtitle="They appear on the nomination form as soon as they are on the list.">
        <a href="{{ route('appointment-letter.examiners', array_filter(['return' => $returnToNomination ? 'nominate' : null])) }}"
           class="btn-secondary">Back to the list</a>
    </x-core::page-header>

    <div class="card card-wide">

        {{-- data-stepper-review because the generated review's default line
             says the form goes to the first approver. This one files nothing
             -- it adds a row to a list the Chair and CGS keep themselves. --}}
        <form method="POST" action="{{ route('appointment-letter.examiners.store') }}" data-stepper
              data-stepper-review="Check the details, then add them to the list. Nothing here is submitted for approval. The examiner simply becomes available to pick on a panel.">
            @csrf
            @if ($returnToNomination)
                <input type="hidden" name="return" value="nominate">
            @endif

            <fieldset class="fstep" data-label="Who they are">
            <p class="fstep-hint">How the examiner is named on the appointment letter.</p>

            <label for="examiner_type">Type</label>
            <select name="examiner_type" id="examiner_type" required
                    class="@error('examiner_type') is-invalid @enderror">
                <option value="internal" @selected(old('examiner_type') === 'internal')>Internal Examiner (UTP)</option>
                <option value="external" @selected(old('examiner_type', 'external') === 'external')>External Examiner</option>
            </select>
            @error('examiner_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="name">Name</label>
            <input type="text" name="name" id="name" required value="{{ old('name') }}"
                   placeholder="e.g. Professor Ir Dr Mohamed Alias Yusof"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="institution">Institution / Faculty</label>
            <input type="text" name="institution" id="institution" required value="{{ old('institution') }}"
                   class="@error('institution') is-invalid @enderror">
            @error('institution') <p class="field-error">{{ $message }}</p> @enderror

            <label for="expertise">Area of Expertise</label>
            <input type="text" name="expertise" id="expertise" required value="{{ old('expertise') }}"
                   class="@error('expertise') is-invalid @enderror">
            @error('expertise') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="fstep" data-label="Where the pack goes">
            <p class="fstep-hint">
                On approval the appointment letter is posted and the pack emailed.
                Both come from here.
            </p>

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="address">Postal Address <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="address" id="address" rows="4"
                      class="@error('address') is-invalid @enderror">{{ old('address') }}</textarea>
            <p class="field-hint">
                Printed under the examiner's name on the appointment letter, one line each.
                CGS can still adjust it when preparing the letter.
            </p>
            @error('address') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Add Examiner</button>
        </form>

        @include('core::partials.form-stepper')
    </div>
</div>

@endsection
