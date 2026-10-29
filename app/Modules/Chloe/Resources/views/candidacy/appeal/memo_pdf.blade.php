<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {{-- Tahoma, registered at render time by
             CandidacyAppealController::registerMemoFont() -- confirmed as
             the source .docx's actual font (96 of its 97 text runs), not
             guessed from the rendered look. Falls back to Dompdf's bundled
             DejaVu Sans only if that registration is ever skipped. --}}
        body { font-family: Tahoma, "DejaVu Sans", sans-serif; font-size: 11.5px; color: #1a1a1a; }
        .logo { text-align: center; margin-bottom: 10px; }
        .logo img { height: 70px; }
        h1.title { text-align: center; font-size: 16px; letter-spacing: 1px; margin: 0 0 18px; }

        table.routing { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.routing td { border-top: 1px solid #1a1a1a; border-bottom: 1px solid #1a1a1a; padding: 6px 8px; vertical-align: top; }
        table.routing tr + tr td { border-top: none; }
        table.routing td.label { width: 70px; font-weight: bold; }
        table.routing td.sep { width: 14px; }

        .subject { font-weight: bold; text-decoration: underline; margin: 18px 0 14px; }
        .body p { line-height: 1.6; margin: 0 0 12px; }

        .sign-block { margin-top: 46px; }
        .sign-line { border-top: 1px solid #1a1a1a; width: 260px; margin-bottom: 4px; }

        .decision { margin: 28px 0; font-weight: bold; }
        .decision .struck { text-decoration: line-through; font-weight: normal; color: #666; }

        .footer { text-align: center; font-size: 10px; color: #555; margin-top: 40px;
                  border-top: 1px solid #1a1a1a; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="logo">
        <img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS">
    </div>
    <h1 class="title">MEMORANDUM</h1>

    <table class="routing">
        <tr>
            <td class="label">To</td><td class="sep">:</td>
            <td>
                {{ $dean?->name ?? 'Dean, Postgraduate and Research' }}<br>
                Dean, Postgraduate &amp; Research<br>
                Universiti Teknologi PETRONAS
            </td>
        </tr>
        <tr>
            <td class="label">Through</td><td class="sep">:</td>
            <td>{{ $chair?->name ?? '—' }} (Chair of Department)</td>
        </tr>
        <tr>
            <td class="label">Through</td><td class="sep">:</td>
            <td>{{ $detail->supervisor->name ?? '—' }} (Supervisor)</td>
        </tr>
        <tr>
            <td class="label">From</td><td class="sep">:</td>
            <td>
                {{ $student->name }}@if ($student->matric_no) ({{ $student->matric_no }})@endif<br>
                {{ $detail->department }}
            </td>
        </tr>
        <tr>
            <td class="label">Date</td><td class="sep">:</td>
            <td>{{ $decidedAt->format('j F Y') }}</td>
        </tr>
    </table>

    <p class="subject">Appeal for Extension of Candidacy</p>

    <div class="body">
        <p>Dear {{ $dean?->name ?? 'Sir/Madam' }},</p>

        <p>{{ $detail->reason }}</p>
    </div>

    <p>Yours sincerely,</p>

    <div class="sign-block">
        <div class="sign-line"></div>
        <p>
            {{ $student->name }}<br>
            {{ $student->matric_no ?? '—' }}<br>
            {{ $detail->department }}
        </p>
    </div>

    <p class="decision">
        <span>Approved</span> / <span class="struck">Not Approved</span>
    </p>

    <div class="sign-block">
        <div class="sign-line"></div>
        <p>
            {{ $dean?->name ?? 'Dean, Postgraduate and Research' }}<br>
            Dean<br>
            Postgraduate and Research
        </p>
    </div>

    <div class="footer">Universiti Teknologi PETRONAS</div>
</body>
</html>
