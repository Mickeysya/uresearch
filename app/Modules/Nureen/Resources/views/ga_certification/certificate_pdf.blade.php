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
    </style>
</head>
<body>
    <div class="letterhead">
        <h1>Universiti Teknologi PETRONAS</h1>
        <h2>Centre for Graduate Studies (CGS)</h2>
    </div>

    <p>Ref: UResearch/{{ $detail->appointment_type }}/{{ $application->id }}</p>
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
</body>
</html>
