{{-- Claims-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Purpose:</b> {{ $detail->purpose_of_claim }}</p>
    <p><b>Bank Account No:</b> {{ $detail->bank_account_no }}</p>
    <p><b>Total Claim:</b> RM {{ number_format($detail->total_claim_amount, 2) }}</p>
    <p><b>Less Cash Advance:</b> RM {{ number_format($detail->less_cash_advance, 2) }}</p>
    <p><b>Balance:</b> RM {{ number_format($detail->claim_balance, 2) }}</p>

    <table class="recent-activity-table" style="margin-top: 12px;">
        <tr>
            <th>Date</th>
            <th>From</th>
            <th>To</th>
            <th>Flight/Train</th>
            <th>Meal</th>
            <th>Lodging</th>
            <th>Misc</th>
        </tr>
        @foreach ($detail->items as $item)
            <tr>
                <td>{{ $item->item_date->format('j M Y') }}</td>
                <td>{{ $item->travel_from }}</td>
                <td>{{ $item->travel_to }}</td>
                <td>RM {{ number_format($item->flight_train_amount, 2) }}</td>
                <td>RM {{ number_format($item->meal_allowance, 2) }}</td>
                <td>RM {{ number_format($item->lodging_amount, 2) }}</td>
                <td>RM {{ number_format($item->misc_amount, 2) }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
