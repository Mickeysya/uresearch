@extends('core::layouts.app')

@section('title', 'New Claims Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Student Claims Application" />

    @include('norhanis::partials.draft-banner')

    <div class="card card-wide">
        {{-- A claim pays for a publication CGS has already approved, so with
             none there is nothing to claim for. See ClaimsController::create(). --}}
        @if ($publications->isEmpty())
            <p class="message-info">You have no approved publication yet. A claim must be for a
               publication application that has been approved, so you can claim once yours is.</p>
            <p><a href="{{ route('publication.create') }}">Apply for publication funding</a></p>
        @else
        <form method="POST" action="{{ route('claims.store') }}" enctype="multipart/form-data" class="app-form" data-stepper
              data-draft-form data-resume-step="{{ $draftStep }}">
            @csrf
            @include('norhanis::partials.draft-fields')

            <fieldset class="fstep" data-label="Claim details">
            <p class="fstep-hint">Journal publication claims only. Choose the approved publication you are claiming for, then enter the amount and where it should be paid.</p>

            <label for="publication_application_id">Publication</label>
            <select name="publication_application_id" id="publication_application_id" required
                    class="@error('publication_application_id') is-invalid @enderror">
                <option value="">-- Select an approved publication --</option>
                @foreach ($publications as $publication)
                    <option value="{{ $publication->application_id }}" @selected((string) old('publication_application_id') === (string) $publication->application_id)>
                        #{{ $publication->application_id }} · {{ $publication->title_of_paper }} ({{ $publication->title_of_conference_journal }})
                        · {{ $publication->currency_type }} {{ number_format((float) $publication->conference_journal_fee, 2) }}
                    </option>
                @endforeach
            </select>
            @error('publication_application_id') <p class="field-error">{{ $message }}</p> @enderror

            <label for="total_claims">Total Claim (RM)</label>
            <input type="number" step="0.01" min="0.01" max="50000" name="total_claims" id="total_claims" required
                   value="{{ old('total_claims') }}"
                   class="@error('total_claims') is-invalid @enderror">
            @error('total_claims') <p class="field-error">{{ $message }}</p> @enderror

            <label for="bank_name">Bank Name</label>
            <input type="text" name="bank_name" id="bank_name" required
                   value="{{ old('bank_name') }}"
                   class="@error('bank_name') is-invalid @enderror">
            @error('bank_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="bank_account_no">Bank Account No</label>
            <input type="text" name="bank_account_no" id="bank_account_no" required
                   value="{{ old('bank_account_no') }}"
                   class="@error('bank_account_no') is-invalid @enderror">
            @error('bank_account_no') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="fstep" data-label="Attachments">
            <p class="fstep-hint">Attach each document under its own heading. You can add more than one file to each.</p>

            <x-nureen::multi-file-upload name="receipt" label="Receipt" :required="true"
                :existing="$attached->get('Receipt', collect())"
                :allowed="['pdf', 'jpg', 'jpeg', 'png']" />

            <x-nureen::multi-file-upload name="proof_of_payment" label="Proof of Payment" :required="true"
                :existing="$attached->get('Proof of Payment', collect())"
                :allowed="['pdf', 'jpg', 'jpeg', 'png']" />

            <x-nureen::multi-file-upload name="invoice" label="Invoice" :required="true"
                :existing="$attached->get('Invoice', collect())"
                :allowed="['pdf', 'jpg', 'jpeg', 'png']" />

            <x-nureen::multi-file-upload name="passport" label="Passport" :required="false"
                :existing="$attached->get('Passport', collect())"
                :allowed="['pdf', 'jpg', 'jpeg', 'png']"
                hint="Required for international students only." />
            </fieldset>

            <button type="submit">Submit Application</button>
            @include('norhanis::partials.draft-save')
        </form>
        @endif
    </div>
</div>

@include('core::partials.form-stepper')
@includeIf('nureen::partials.draft-form-script')
@include('norhanis::partials.file-remove')
@endsection
