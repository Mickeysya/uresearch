<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .letterhead { text-align: center; margin-bottom: 30px; }
        .letterhead h1 { font-size: 16px; margin: 0; }
        .letterhead h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .title { text-align: center; text-decoration: underline; margin: 30px 0; font-size: 14px; }
        .body p { line-height: 1.7; }
        .signature { margin-top: 60px; }
        .signature .line { border-top: 1px solid #1a1a1a; width: 220px; margin-top: 50px; }
        .verify { margin-top: 40px; display: table; width: 100%; }
        .verify .qr { display: table-cell; width: 90px; vertical-align: top; }
        .verify .qr img { width: 80px; height: 80px; }
        .verify .text { display: table-cell; vertical-align: top; font-size: 10px; color: #555; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="letterhead">
        <h1>Universiti Teknologi PETRONAS</h1>
        <h2>Centre for Graduate Studies (CGS)</h2>
    </div>

    <p>Ref: {{ $detail->reference_number }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">
        {{ $detail->appointment_type === 'GRA' ? 'GRADUATE RESEARCH ASSISTANT' : 'GRADUATE ASSISTANT' }}
        CERTIFICATION LETTER
    </div>

    <div class="body">
        <p>This is to certify that <b>{{ $student->name }}</b>
            (Matric No: {{ $student->matric_no ?? '—' }}), a postgraduate student of
            {{ $student->programme ?? 'Universiti Teknologi PETRONAS' }},
            served as a <b>{{ $detail->appointment_type }}</b> at the
            {{ $student->department ?? 'Centre for Graduate Studies' }}
            from <b>{{ $detail->period_start->format('j F Y') }}</b>
            to <b>{{ $detail->period_end->format('j F Y') }}</b>.</p>

        <p>This letter is issued for the purpose of: {{ $detail->purpose }}.</p>

        <p>This letter is generated and digitally endorsed through UResearch 2.0
            and does not require a physical signature.</p>
    </div>

    <div class="signature">
        <p>Endorsed by:</p>
        <div class="line"></div>
        <p>{{ $endorsedBy->name }}<br>{{ $endorsedBy->roleLabel() }}<br>Centre for Graduate Studies</p>
    </div>

    {{--
        Scan to verify, or read the reference above into the verification
        page by hand. $qrDataUri is null until a QR library is added to
        composer.json (see SHARED_CHANGES.md) -- Support\QrCode\UnavailableQrCodeGenerator
        is what returns null today, and this falls back to the plain URL
        as text until that changes.
    --}}
    <div class="verify">
        @if ($qrDataUri)
            <div class="qr"><img src="{{ $qrDataUri }}" alt="Verification QR code"></div>
        @endif
        <div class="text">
            Verify this letter at:<br>
            {{ $verificationUrl }}
        </div>
    </div>
</body>
</html>
