{{-- The split table of the Memo Request Split Budget, as on the paper form.
     Shared by the signer's page, the student's sign-off page and the
     approver's queue card. --}}
<table class="recent-activity-table" style="margin-top: 12px;">
    <tr>
        <th>Cost Centre</th>
        <th>Amount</th>
        <th>Percentage %</th>
    </tr>
    <tr>
        <td>{{ $detail->cost_centre }}</td>
        <td>RM {{ number_format($memo->cgs_amount, 2) }}</td>
        <td>{{ $memo->percentOf('cgs_amount') }}</td>
    </tr>
    <tr>
        <td>{{ $memo->grant_name }} ({{ $memo->grant_cost_centre }})</td>
        <td>RM {{ number_format($memo->grant_amount, 2) }}</td>
        <td>{{ $memo->percentOf('grant_amount') }}</td>
    </tr>
    <tr>
        <td><b>Total</b></td>
        <td><b>RM {{ number_format($memo->fee_myr, 2) }}</b></td>
        <td><b>100</b></td>
    </tr>
</table>
