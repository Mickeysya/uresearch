<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        .head { text-align: center; margin-bottom: 14px; }
        .head .utp { font-size: 12px; font-weight: bold; }
        .head .memo { font-size: 12px; font-weight: bold; margin-top: 10px; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        .parties { border-top: 2px solid #1a1a1a; }
        .parties td { border-bottom: 2px solid #1a1a1a; padding: 6px 4px; vertical-align: middle; }
        .parties td.key { width: 13%; font-weight: bold; }
        .parties td.sig { width: 34%; text-align: center; }
        .parties img { height: 38px; }
        .small { font-size: 9px; color: #444; }
        .subject { font-weight: bold; text-decoration: underline; margin: 22px 0 10px; }
        p { margin: 0 0 8px; line-height: 1.5; }
        .split { margin: 14px 0; }
        .split td, .split th { border: 1px solid #1a1a1a; padding: 4px 8px; }
        .split th { text-align: center; }
        .right { text-align: right; }
        .foot { margin-top: 26px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="utp">Universiti Teknologi PETRONAS</div>
        <div class="memo">MEMORANDUM</div>
    </div>

    <table class="parties">
        <tr>
            <td class="key">To</td>
            <td>
                {{ $approval?->approver?->name }}@if ($approval?->approver)<br>@endif
                Senior Director, Centre for Graduate Studies
            </td>
            <td class="sig">
                @if ($approval)
                    <b>APPROVED</b><br>
                    <span class="small">{{ $approval->approver?->name }}, {{ $approval->created_at->format('j M Y, g:ia') }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="key">Through</td>
            <td>{{ $memo->head_name }}<br>Head, {{ $memo->centre_name }}</td>
            <td class="sig">
                @if ($memo->head_signature_png)
                    <img src="data:image/png;base64,{{ $memo->head_signature_png }}" alt="Signature"><br>
                    <span class="small">{{ $memo->head_signed_at?->format('j M Y, g:ia') }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="key">From</td>
            <td>{{ $memo->supervisor_name }}@if ($memo->supervisor_department)<br>{{ $memo->supervisor_department }}@endif</td>
            <td class="sig">
                @if ($memo->supervisor_signature_png)
                    <img src="data:image/png;base64,{{ $memo->supervisor_signature_png }}" alt="Signature"><br>
                    <span class="small">{{ $memo->supervisor_signed_at?->format('j M Y, g:ia') }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="key">Date</td>
            <td colspan="2">{{ $memo->supervisor_signed_at?->format('j F Y') }}</td>
        </tr>
    </table>

    <p class="subject">
        Request to utilize Graduate Studies Fund and {{ $memo->grant_name }} Grant cost centre ({{ $memo->grant_cost_centre }})
        for Student {{ $detail->type_of_request === 'publication_journal' ? 'Journal' : 'Conference' }}
    </p>

    <p>
        With regard to the above matter, I would like to request for kind approval to utilize the {{ $memo->grant_name }} grant
        to cover the outstanding fee amount which exceeds the sum of RM{{ number_format(\App\Modules\Norhanis\Models\PublicationMemo::CGS_CAP) }}
        covered by CGS fund for student to {{ $detail->type_of_request === 'publication_journal' ? 'publish' : 'present' }}
        "{{ $detail->title_of_paper }}" ({{ $detail->title_of_conference_journal }}).
    </p>

    <p>The split details are as follows:</p>

    <table class="split">
        <tr>
            <th>Cost Centre</th>
            <th>Amount</th>
            <th>Percentage %</th>
            <th>Student Details</th>
        </tr>
        <tr>
            <td>{{ $detail->cost_centre }}</td>
            <td class="right">RM {{ number_format($memo->cgs_amount, 2) }}</td>
            <td class="right">{{ $memo->percentOf('cgs_amount') }}</td>
            <td>Name: {{ $student?->name }}</td>
        </tr>
        <tr>
            <td>{{ $memo->grant_name }} ({{ $memo->grant_cost_centre }})</td>
            <td class="right">RM {{ number_format($memo->grant_amount, 2) }}</td>
            <td class="right">{{ $memo->percentOf('grant_amount') }}</td>
            <td>ID: {{ $student?->matric_no }}</td>
        </tr>
        <tr>
            <td>Total</td>
            <td class="right">RM {{ number_format($memo->fee_myr, 2) }}</td>
            <td class="right">100</td>
            <td>Dept: {{ $student?->department }}</td>
        </tr>
    </table>

    <p>I hope this request will reach to your kind consideration.</p>
    <p>Thank you.</p>

    <p class="foot">
        {{ $memo->supervisor_name }}@if ($memo->supervisor_department)<br>{{ $memo->supervisor_department }}@endif
    </p>

    <p class="small" style="margin-top: 30px;">
        Signed online through UResearch 2.0 for {{ $application->reference() }}. Each signer signed from a personal link
        sent to their email address ({{ $memo->supervisor_email }}; {{ $memo->head_email }}), and the date and time of each
        signature were recorded by the system.
        @if (! $approval) The Senior Director's decision is recorded when the application reaches final approval. @endif
    </p>
</body>
</html>
