<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dean PFR Report — Appeal #{{ $application->id }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; line-height: 1.5; }
        .logo { text-align: center; margin-bottom: 16px; }
        .logo img { height: 58px; }
        h1 { font-size: 12px; text-transform: uppercase; text-align: center;
             border: 1px solid #000; padding: 6px; margin: 0 0 6px; }
        .subtitle { text-align: center; font-size: 9.5px; color: #444; margin-bottom: 20px; }
        h2 { font-size: 11px; text-transform: uppercase; margin: 20px 0 6px;
             border-bottom: 1px solid #000; padding-bottom: 3px; }
        table.details { border-collapse: collapse; width: 100%; margin: 6px 0 12px; }
        table.details td { padding: 3px 4px; vertical-align: top; }
        table.details td.label { width: 165px; }
        table.details td.sep { width: 8px; }
        table.grid { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 4px 6px; text-align: left; vertical-align: top; }
        .quote { border-left: 3px solid #888; padding-left: 10px; margin: 6px 0 12px; }
        .sig-line { margin-top: 40px; border-top: 1px solid #000; width: 280px; }
        .footer { margin-top: 26px; font-size: 8.5px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="logo">
        <img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS">
    </div>

    <h1>Dean PFR Report — Hardbound Submission Appeal</h1>
    <p class="subtitle">
        Compiled by the Centre for Graduate Studies for the ruling of the Senior Executive, CGS
    </p>

    <h2>1. Candidate and Appeal</h2>
    <table class="details">
        <tr><td class="label">Report Ref</td><td class="sep">:</td><td>UTP/CGS/PFR/{{ $application->id }}</td></tr>
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Matric Number</td><td class="sep">:</td><td>{{ $originalDetail?->matric_no ?: ($student?->matric_no ?: '—') }}</td></tr>
        <tr><td class="label">Programme</td><td class="sep">:</td><td>{{ $originalDetail?->programme ?? '—' }}</td></tr>
        <tr><td class="label">Supervisor</td><td class="sep">:</td><td>{{ $originalDetail?->supervisor_name ?? '—' }}</td></tr>
        <tr><td class="label">Appeal Filed</td><td class="sep">:</td><td>{{ $application->submitted_at?->format('j F Y') ?? '—' }}</td></tr>
        <tr><td class="label">Report Compiled</td><td class="sep">:</td><td>{{ $issuedAt->format('j F Y') }}</td></tr>
    </table>

    <h2>2. Submission Under Appeal</h2>
    <table class="details">
        <tr><td class="label">Submission</td><td class="sep">:</td><td>#{{ $detail->hardbound_application_id }}</td></tr>
        <tr><td class="label">Title of the Thesis</td><td class="sep">:</td><td><b>{{ $originalDetail?->thesis_title ?? '—' }}</b></td></tr>
        <tr><td class="label">Submitted</td><td class="sep">:</td><td>{{ $original?->submitted_at?->format('j F Y') ?? '—' }}</td></tr>
        <tr><td class="label">Outcome</td><td class="sep">:</td><td>{{ ucfirst($original?->status ?? 'unknown') }}</td></tr>
    </table>

    <h2>3. Decisions on the Original Submission</h2>
    @if ($originalHistory->isEmpty())
        <p>No decisions are recorded against the original submission.</p>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 140px;">Stage</th>
                    <th style="width: 80px;">Decision</th>
                    <th style="width: 90px;">Date</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($originalHistory as $row)
                    <tr>
                        <td>{{ $row->stage_label }}</td>
                        <td>{{ ucfirst($row->decision) }}</td>
                        <td>{{ $row->created_at?->format('j M Y') }}</td>
                        <td>{{ $row->remarks ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>4. Grounds Submitted by the Candidate</h2>
    <div class="quote">{{ $detail->justification }}</div>
    <p style="font-size: 9.5px; color: #555;">
        The candidate's appeal memo is archived against this appeal and should be read with
        this report.
    </p>

    <h2>5. Recommendation of the Non-Executive, CGS</h2>
    <div class="quote">{{ $detail->pfr_recommendation ?: 'No recommendation recorded.' }}</div>

    <h2>6. Ruling</h2>
    <p>
        For the decision of the Senior Executive, CGS. Upholding this appeal permits the
        candidate to resubmit the hardbound thesis; dismissing it leaves the original
        decision on submission #{{ $detail->hardbound_application_id }} standing.
    </p>

    <table class="details" style="margin-top: 16px;">
        <tr><td class="label">Ruling</td><td class="sep">:</td><td>Upheld / Dismissed</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td></td></tr>
    </table>

    <div class="sig-line"></div>
    <p>
        Senior Executive<br>
        Centre for Graduate Studies<br>
        Universiti Teknologi PETRONAS
    </p>

    <div class="footer">
        Generated by UResearch 2.0 on {{ $issuedAt->format('j F Y, g:ia') }} and archived
        against Appeal #{{ $application->id }}.
    </div>
</body>
</html>
