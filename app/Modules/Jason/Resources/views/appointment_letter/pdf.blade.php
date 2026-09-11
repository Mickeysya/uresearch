<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Appointment Letter — Application #{{ $application->id }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1a1a1a; }
        .letterhead { text-align: center; border-bottom: 2px solid #002147; padding-bottom: 12px; margin-bottom: 24px; }
        .letterhead h1 { font-size: 16px; margin: 0; color: #002147; }
        .letterhead p { margin: 2px 0; font-size: 11px; }
        .ref-line { display: flow-root; margin-bottom: 18px; }
        .ref-line .date { float: right; }
        table.details { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
        table.details td { padding: 4px 6px; vertical-align: top; }
        table.details td.label { width: 160px; font-weight: bold; }
        .signature-block { margin-top: 60px; }
        .signature-line { margin-top: 48px; border-top: 1px solid #1a1a1a; width: 260px; }
        .footer-note { margin-top: 40px; font-size: 9.5px; color: #555; }
    </style>
</head>
<body>
    <div class="letterhead">
        <h1>UNIVERSITI TEKNOLOGI PETRONAS</h1>
        <p>Centre for Graduate Studies (CGS)</p>
        <p>32610 Seri Iskandar, Perak Darul Ridzuan, Malaysia</p>
    </div>

    <div class="ref-line">
        <span class="date">{{ $issuedAt->format('j F Y') }}</span>
        <span>Ref: CGS/APPT/{{ $application->id }}</span>
    </div>

    <p>
        {{ $detail->examiner_name }}<br>
        {{ $detail->examiner_institution }}<br>
        {{ $detail->examiner_email }}
    </p>

    <p><strong>Dear {{ $detail->examiner_name }},</strong></p>

    <p><strong>RE: APPOINTMENT AS EXAMINER</strong></p>

    <p>
        We are pleased to inform you that, on the recommendation of the
        Department and with the endorsement of the Academic Executive, the
        Dean of Postgraduate Studies &amp; Research has approved your
        appointment as an examiner for the candidature detailed below.
    </p>

    <table class="details">
        <tr>
            <td class="label">Candidate</td>
            <td>{{ $student?->name ?? 'Not on record' }}@if($student?->matric_no) ({{ $student->matric_no }})@endif</td>
        </tr>
        <tr>
            <td class="label">Programme</td>
            <td>{{ $student?->programme ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Examiner</td>
            <td>{{ $detail->examiner_name }}</td>
        </tr>
        <tr>
            <td class="label">Institution</td>
            <td>{{ $detail->examiner_institution }}</td>
        </tr>
        <tr>
            <td class="label">Area of Expertise</td>
            <td>{{ $detail->examiner_expertise }}</td>
        </tr>
        <tr>
            <td class="label">Appointment Reference</td>
            <td>Application #{{ $application->id }}</td>
        </tr>
    </table>

    <p>
        We would be grateful if you could confirm your acceptance of this
        appointment by replying to this letter. Should you have any queries,
        please do not hesitate to contact the Centre for Graduate Studies.
    </p>

    <p>Thank you for your support of postgraduate research at Universiti Teknologi PETRONAS.</p>

    <div class="signature-block">
        <p>Yours sincerely,</p>
        <div class="signature-line"></div>
        <p>
            Dean of Postgraduate Studies &amp; Research<br>
            Centre for Graduate Studies<br>
            Universiti Teknologi PETRONAS
        </p>
    </div>

    <div class="footer-note">
        This letter was generated automatically by UResearch 2.0 on
        {{ $issuedAt->format('j F Y, g:ia') }} and archived against
        Application #{{ $application->id }}.
    </div>
</body>
</html>
