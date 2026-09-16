@php
    /**
     * Confirmation of Correction to Thesis, generated from what the student
     * declared and regenerated at every approval so that each approver's
     * signature and date are stamped in as the form moves up the chain.
     *
     * $signatures is keyed by stage key: ['supervisor' => [
     *     'name' => ..., 'date' => Carbon|null, 'image' => data-uri|null ], ...]
     * A stage that has not signed yet has no entry.
     *
     * Layout is the portal's own until the official CGS form is supplied;
     * the data and the signature blocks are what the office needs either way.
     */
    $blocks = [
        'supervisor' => ['title' => 'Confirmation by Supervisor', 'text' => 'I confirm that the corrections listed above have been made to my satisfaction and that the thesis is ready for hardbound submission.'],
        'chair'      => ['title' => 'Endorsement by Chair of Department', 'text' => 'I endorse the supervisor\'s confirmation on behalf of the Department.'],
        'cgs_review' => ['title' => 'Accepted by Centre for Graduate Studies', 'text' => 'The corrected hardbound thesis and this confirmation have been received and accepted.'],
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Confirmation of Correction to Thesis — #{{ $application->id }}</title>
    <style>
        @page { margin: 16mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; line-height: 1.5; }
        .code { text-align: right; font-size: 9.5px; color: #333; }
        .logo { text-align: center; margin: 0 0 10px; }
        .logo img { height: 56px; }
        h1 { font-size: 12.5px; text-transform: uppercase; text-align: center; border: 1px solid #000; padding: 6px; margin: 0 0 18px; }
        h2 { font-size: 11px; text-transform: uppercase; margin: 16px 0 6px; border-bottom: 1px solid #000; padding-bottom: 3px; }
        table.details { border-collapse: collapse; width: 100%; margin: 4px 0 8px; }
        table.details td { padding: 3px 4px; vertical-align: top; }
        table.details td.label { width: 160px; }
        table.details td.sep { width: 8px; }
        .box { border: 1px solid #000; padding: 8px 10px; min-height: 90px; margin: 4px 0 6px; white-space: pre-wrap; }
        table.sig { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.sig td { border: 1px solid #000; vertical-align: top; padding: 8px 10px; width: 33.33%; }
        table.sig .title { font-weight: bold; font-size: 10.5px; margin-bottom: 4px; }
        table.sig .text { font-size: 9.5px; color: #222; min-height: 52px; }
        .sig-area { height: 58px; margin: 8px 0 4px; border-bottom: 1px solid #000; position: relative; }
        .sig-area img { max-height: 54px; max-width: 100%; position: absolute; bottom: 2px; left: 0; }
        .sig-meta { font-size: 9.5px; }
        .sig-meta .pending { color: #777; font-style: italic; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="code">UTP/CGS — Confirmation of Correction to Thesis</div>
    <div class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS"></div>

    <h1>Confirmation of Correction to Thesis</h1>

    <h2>1. Candidate</h2>
    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Matric Number</td><td class="sep">:</td><td>{{ $detail->matric_no ?: '—' }}</td></tr>
        <tr><td class="label">Programme</td><td class="sep">:</td><td>{{ $detail->programme }}</td></tr>
        <tr><td class="label">Supervisor</td><td class="sep">:</td><td>{{ $detail->supervisor_name }}</td></tr>
        <tr><td class="label">Title of the Thesis</td><td class="sep">:</td><td><b>{{ $detail->thesis_title }}</b></td></tr>
        <tr><td class="label">Submitted</td><td class="sep">:</td><td>{{ $application->submitted_at?->format('j F Y') ?? '—' }}@if ($detail->isResubmission()) (resubmission of #{{ $detail->resubmission_of_id }})@endif</td></tr>
    </table>

    <h2>2. Declaration by Candidate</h2>
    <p>
        I confirm that the corrections required by the examiners have been made to the thesis
        as detailed below, and that the hardbound copy submitted incorporates all of them.
    </p>
    <div class="box">{{ $detail->corrections_made }}</div>
    <table class="details">
        <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td>{{ $application->submitted_at?->format('j F Y') ?? '—' }}</td></tr>
    </table>

    <h2>3. Confirmation and Endorsement</h2>
    <table class="sig">
        <tr>
            @foreach ($blocks as $key => $block)
                @php($sig = $signatures[$key] ?? null)
                <td>
                    <div class="title">{{ $block['title'] }}</div>
                    <div class="text">{{ $block['text'] }}</div>
                    <div class="sig-area">
                        @if ($sig && $sig['image'])
                            <img src="{{ $sig['image'] }}" alt="Signature of {{ $sig['name'] }}">
                        @endif
                    </div>
                    <div class="sig-meta">
                        @if ($sig)
                            Name: {{ $sig['name'] }}<br>
                            Date: {{ $sig['date']?->format('j F Y') }}
                        @else
                            <span class="pending">Name:</span><br>
                            <span class="pending">Date:</span>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="footer">
        Generated by UResearch 2.0 on {{ $issuedAt->format('j F Y, g:ia') }} against Application
        #{{ $application->id }}. Signatures are applied electronically by each approver's own
        account at the moment of approval and are recorded in the application's decision history.
    </div>
</body>
</html>
