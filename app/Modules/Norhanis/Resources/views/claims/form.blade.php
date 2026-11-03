@extends('core::layouts.app')

@section('title', 'New Claims Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Student Claims Application" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('claims.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Claim details">
            <p class="fstep-hint">Journal publication claims only. Enter the amount you are claiming and where it should be paid.</p>

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
            <p class="fstep-hint">PDF, JPG or PNG, 5 MB max per file.</p>

            <label for="receipt">Receipt</label>
            <input type="file" name="receipt" id="receipt" required accept=".pdf,.jpg,.jpeg,.png"
                   class="@error('receipt') is-invalid @enderror">
            <span data-file-name-for="receipt" style="display: block; font-size: 0.85em; color: var(--text-grey);"></span>
            @error('receipt') <p class="field-error">{{ $message }}</p> @enderror

            <label for="proof_of_payment">Proof of Payment</label>
            <input type="file" name="proof_of_payment" id="proof_of_payment" required accept=".pdf,.jpg,.jpeg,.png"
                   class="@error('proof_of_payment') is-invalid @enderror">
            <span data-file-name-for="proof_of_payment" style="display: block; font-size: 0.85em; color: var(--text-grey);"></span>
            @error('proof_of_payment') <p class="field-error">{{ $message }}</p> @enderror

            <label for="invoice">Invoice</label>
            <input type="file" name="invoice" id="invoice" required accept=".pdf,.jpg,.jpeg,.png"
                   class="@error('invoice') is-invalid @enderror">
            <span data-file-name-for="invoice" style="display: block; font-size: 0.85em; color: var(--text-grey);"></span>
            @error('invoice') <p class="field-error">{{ $message }}</p> @enderror

            <label for="passport">Passport</label>
            <p class="field-hint">Required for international students only.</p>
            <input type="file" name="passport" id="passport" accept=".pdf,.jpg,.jpeg,.png"
                   class="@error('passport') is-invalid @enderror">
            <span data-file-name-for="passport" style="display: block; font-size: 0.85em; color: var(--text-grey);"></span>
            @error('passport') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>

<script @cspNonce>
    (function () {
        // Filename next to each file field, so a student can tell at a
        // glance which of the four inputs still needs picking.
        document.querySelectorAll('form[data-stepper] input[type="file"]').forEach(function (input) {
            var out = document.querySelector('[data-file-name-for="' + input.name + '"]');
            input.addEventListener('change', function () {
                out.textContent = input.files && input.files.length ? input.files[0].name : '';
            });
        });
    })();
</script>

@include('core::partials.form-stepper')
@include('norhanis::partials.file-remove')
@endsection
