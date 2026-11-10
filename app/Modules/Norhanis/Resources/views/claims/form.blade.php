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
            <p class="message-info">You have no approved journal publication yet. A claim must be for a
               journal publication application that has been approved, so you can claim once yours is.</p>
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
                    <option value="{{ $publication->application_id }}" @selected((string) old('publication_application_id') === (string) $publication->application_id)
                            data-title="{{ $publication->title_of_paper }}"
                            data-journal="{{ $publication->title_of_conference_journal }}"
                            data-publisher="{{ $publication->organizer_publisher }}"
                            data-fee="{{ number_format((float) $publication->conference_journal_fee, 2, '.', '') }}"
                            data-fee-shown="{{ number_format((float) $publication->conference_journal_fee, 2) }}"
                            data-currency="{{ $publication->currency_type }}"
                            data-cost-centre="{{ $publication->costCentreLabel() }}">
                        #{{ $publication->application_id }} · {{ $publication->title_of_paper }} ({{ $publication->title_of_conference_journal }})
                        · {{ $publication->currency_type }} {{ number_format((float) $publication->conference_journal_fee, 2) }}
                    </option>
                @endforeach
            </select>
            @error('publication_application_id') <p class="field-error">{{ $message }}</p> @enderror

            {{-- What the chosen publication says, read-only, so the amount can be
                 checked against it. Filled by the script below from the option's
                 data attributes (textContent only, never HTML). --}}
            <div class="claim-publication" data-publication-summary hidden>
                <dl>
                    <dt>Title</dt><dd data-summary="title"></dd>
                    <dt>Journal / Publisher</dt><dd data-summary="venue"></dd>
                    <dt>Fee</dt><dd data-summary="fee"></dd>
                    <dt>Cost centre</dt><dd data-summary="costCentre"></dd>
                </dl>
            </div>

            <label for="total_claims">Total Claim (RM)</label>
            <input type="number" step="0.01" min="0.01" max="50000" name="total_claims" id="total_claims" required
                   value="{{ old('total_claims') }}"
                   class="@error('total_claims') is-invalid @enderror">
            <p class="field-hint" data-fee-hint hidden></p>
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
            <p class="fstep-hint">Attach each document under its own heading, one file each.</p>

            @include('norhanis::partials.file-field', ['name' => 'receipt', 'label' => 'Receipt', 'allowed' => ['pdf', 'jpg', 'jpeg', 'png']])

            @include('norhanis::partials.file-field', ['name' => 'proof_of_payment', 'label' => 'Proof of Payment', 'allowed' => ['pdf', 'jpg', 'jpeg', 'png']])

            @include('norhanis::partials.file-field', ['name' => 'invoice', 'label' => 'Invoice', 'allowed' => ['pdf', 'jpg', 'jpeg', 'png']])

            @include('norhanis::partials.file-field', ['name' => 'passport', 'label' => 'Passport', 'required' => false, 'allowed' => ['pdf', 'jpg', 'jpeg', 'png'], 'hint' => 'Required for international students only.'])
            </fieldset>

            <button type="submit">Submit Application</button>
            @include('norhanis::partials.draft-save')
        </form>
        @endif
    </div>
</div>

@push('head')
    <style>
        .claim-publication { margin: 10px 0 4px; padding: 12px 14px; border: 1px solid var(--border-grey); border-radius: 8px; background: var(--page-bg); }
        .claim-publication dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0; font-size: 13.5px; }
        .claim-publication dt { color: var(--text-grey); font-weight: 600; }
        .claim-publication dd { margin: 0; overflow-wrap: anywhere; }
    </style>
@endpush

<script @cspNonce>
    (function () {
        // Show the chosen publication, and prefill Total Claim (RM) with its
        // fee when the fee is in MYR. The field stays editable; the server
        // takes whatever the student leaves in it.
        var select = document.getElementById('publication_application_id');
        var total = document.getElementById('total_claims');
        var box = document.querySelector('[data-publication-summary]');
        var hint = document.querySelector('[data-fee-hint]');
        if (! select || ! total) return;

        var prefilled = null; // the value this script put in, if any

        function field(name) { return box.querySelector('[data-summary="' + name + '"]'); }

        function sync(initial) {
            var option = select.options[select.selectedIndex];
            var chosen = option && option.value !== '';

            box.hidden = ! chosen;
            hint.hidden = true;
            if (! chosen) return;

            var d = option.dataset;
            field('title').textContent = d.title;
            field('venue').textContent = d.journal + ' / ' + d.publisher;
            field('fee').textContent = d.currency + ' ' + d.feeShown;
            field('costCentre').textContent = d.costCentre;

            // Never overwrite an amount the student typed (or a saved draft's).
            var untouched = total.value === '' || total.value === prefilled;

            if (d.currency === 'MYR') {
                if (untouched && ! (initial && total.value !== '')) {
                    total.value = d.fee;
                    prefilled = d.fee;
                }
            } else {
                if (untouched && total.value === prefilled) {
                    total.value = '';
                    prefilled = null;
                }
                hint.textContent = 'Publication fee: ' + d.currency + ' ' + d.feeShown + '. Enter the amount in RM. '
                    + 'If you paid in another currency, convert it using the rate shown on your bank statement or proof of payment.';
                hint.hidden = false;
            }
        }

        select.addEventListener('change', function () { sync(false); });
        sync(true);
    })();
</script>

@include('core::partials.form-stepper')
@includeIf('nureen::partials.draft-form-script')
@include('norhanis::partials.file-remove')
@endsection
