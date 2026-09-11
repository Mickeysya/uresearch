@extends('core::layouts.app')

@section('title', 'New Claims Application')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Student Claims Application</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('claims.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="purpose_of_claim">Purpose of Claim</label>
            <input type="text" name="purpose_of_claim" id="purpose_of_claim" required
                   value="{{ old('purpose_of_claim') }}"
                   class="@error('purpose_of_claim') is-invalid @enderror">
            @error('purpose_of_claim') <p class="field-error">{{ $message }}</p> @enderror

            <label for="bank_account_no">Bank Account No</label>
            <input type="text" name="bank_account_no" id="bank_account_no" required
                   value="{{ old('bank_account_no') }}"
                   class="@error('bank_account_no') is-invalid @enderror">
            @error('bank_account_no') <p class="field-error">{{ $message }}</p> @enderror

            <label for="less_cash_advance">Less Cash Advance (RM)</label>
            <input type="number" step="0.01" name="less_cash_advance" id="less_cash_advance"
                   value="{{ old('less_cash_advance', 0) }}">

            {{-- Total claim amount and balance are computed server-side from the
                 items below, so there is no input field for them here. --}}

            <h3 style="margin-top: 24px; margin-bottom: 4px;">Expense Items</h3>
            @error('items') <p class="field-error">{{ $message }}</p> @enderror

            <div id="items-container">
                <div class="claim-item-row" data-index="0">
                    <p class="queue-meta">Item 1</p>

                    <label>Date</label>
                    <input type="date" name="items[0][item_date]" value="{{ old('items.0.item_date') }}">
                    @error('items.0.item_date') <p class="field-error">{{ $message }}</p> @enderror

                    <label>Travel From</label>
                    <input type="text" name="items[0][travel_from]" value="{{ old('items.0.travel_from') }}">

                    <label>Travel To</label>
                    <input type="text" name="items[0][travel_to]" value="{{ old('items.0.travel_to') }}">

                    <label>Flight/Train Amount (RM)</label>
                    <input type="number" step="0.01" name="items[0][flight_train_amount]" value="{{ old('items.0.flight_train_amount', 0) }}">

                    <label>Meal Allowance (RM)</label>
                    <input type="number" step="0.01" name="items[0][meal_allowance]" value="{{ old('items.0.meal_allowance', 0) }}">

                    <label>Lodging (RM)</label>
                    <input type="number" step="0.01" name="items[0][lodging_amount]" value="{{ old('items.0.lodging_amount', 0) }}">

                    <label>Miscellaneous (RM)</label>
                    <input type="number" step="0.01" name="items[0][misc_amount]" value="{{ old('items.0.misc_amount', 0) }}">
                </div>
            </div>

            <button type="button" id="add-item-btn" class="btn-secondary" style="margin-top: 12px;">
                + Add Another Item
            </button>

            <label for="receipt" style="margin-top: 20px;">Receipt <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="receipt" id="receipt"
                   class="@error('receipt') is-invalid @enderror">
            @error('receipt') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" style="margin-top: 20px;">Submit Application</button>
        </form>
    </div>
</div>

<template id="item-row-template">
    <div class="claim-item-row" data-index="__INDEX__">
        <p class="queue-meta">Item __LABEL__</p>
        <label>Date</label>
        <input type="date" name="items[__INDEX__][item_date]">
        <label>Travel From</label>
        <input type="text" name="items[__INDEX__][travel_from]">
        <label>Travel To</label>
        <input type="text" name="items[__INDEX__][travel_to]">
        <label>Flight/Train Amount (RM)</label>
        <input type="number" step="0.01" name="items[__INDEX__][flight_train_amount]" value="0">
        <label>Meal Allowance (RM)</label>
        <input type="number" step="0.01" name="items[__INDEX__][meal_allowance]" value="0">
        <label>Lodging (RM)</label>
        <input type="number" step="0.01" name="items[__INDEX__][lodging_amount]" value="0">
        <label>Miscellaneous (RM)</label>
        <input type="number" step="0.01" name="items[__INDEX__][misc_amount]" value="0">
        <button type="button" class="btn-secondary remove-item-btn" style="margin-top: 8px;">Remove</button>
    </div>
</template>

<script>
    let itemIndex = 1;
    document.getElementById('add-item-btn').addEventListener('click', function () {
        const template = document.getElementById('item-row-template').innerHTML;
        const html = template
            .replaceAll('__INDEX__', itemIndex)
            .replace('__LABEL__', itemIndex + 1);
        document.getElementById('items-container').insertAdjacentHTML('beforeend', html);
        itemIndex++;
    });

    document.getElementById('items-container').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-item-btn')) {
            e.target.closest('.claim-item-row').remove();
        }
    });
</script>
@endsection