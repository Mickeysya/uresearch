@php
    /**
     * Hardbound Thesis Submission, UTP/CGS/021 -- ORIGINAL and STUDENT'S
     * COPY, as issued. Pre-filled with what the portal knows about the
     * student; the rest (country, intake, passport, date of birth, title)
     * and the signature are theirs to complete by hand before uploading.
     * The office block is CGS's, on paper.
     */
    $boxes = function (?string $text, int $n): array {
        $chars = preg_split('//u', strtoupper((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        return array_pad(array_slice($chars, 0, $n), $n, '');
    };
    $name = array_chunk($boxes($student->name, 54), 27);
    $matric = $boxes($student->matric_no, 7);
    $country = $boxes(null, 6);
    $intake = $boxes(null, 6);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hardbound Thesis Submission: {{ $student->name }}</title>
    <style>
        @page { margin: 12mm 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #000; line-height: 1.5; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .code { text-align: right; margin-bottom: 8px; }
        .code span { border: 1px solid #000; padding: 3px 12px; font-size: 9.5px; }
        .frame { border: 3px double #000; padding: 14px 26px 30px; }
        .copy { text-align: right; }
        .copy span { border: 1px solid #000; padding: 2px 12px; font-size: 9.5px; }
        table.hdr { width: 100%; border-collapse: collapse; margin: 6px 0 22px; }
        table.hdr td { vertical-align: middle; padding: 0; }
        table.hdr td.logo { width: 90px; }
        table.hdr img { height: 82px; }
        table.hdr .u { font-size: 19px; font-weight: bold; text-align: center; }
        table.hdr .c { font-size: 13px; font-weight: bold; text-align: center; }
        table.hdr .t { font-size: 14px; font-weight: bold; text-align: center; margin-top: 8px; }
        h2 { font-size: 12px; font-weight: bold; margin: 0 0 8px; }
        table.det { border-collapse: collapse; width: 100%; }
        table.det td { padding: 5px 0; vertical-align: bottom; }
        table.det td.l { width: 96px; padding-right: 6px; white-space: nowrap; }
        table.det td.line { border-bottom: 1px solid #000; }
        table.grid { border-collapse: collapse; display: inline-table; }
        table.grid td { border: 1px solid #000; width: 19px; height: 20px; text-align: center; font-size: 10.5px; padding: 0; }
        .hint { font-size: 10px; }
        .cert { margin: 14px 0 22px; text-align: justify; }
        table.sig { width: 100%; border-collapse: collapse; }
        table.sig td { vertical-align: top; padding: 0; }
        .sigline { height: 40px; border-bottom: 1px solid #000; }
        .rule { border-top: 3px double #000; margin: 26px 0 14px; }
        .office { text-decoration: underline; margin-bottom: 6px; }
        .blank { display: inline-block; border-bottom: 1px solid #000; width: 290px; }
    </style>
</head>
<body>
@foreach (['ORIGINAL', "STUDENT'S COPY"] as $copy)
<div class="sheet">
    <div class="code"><span>UTP/CGS/021</span></div>
    <div class="frame">
        <div class="copy"><span>{{ $copy }}</span></div>

        <table class="hdr">
            <tr>
                <td class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="UTP"></td>
                <td>
                    <div class="u">UNIVERSITI TEKNOLOGI PETRONAS</div>
                    <div class="c">CENTRE FOR GRADUATE STUDIES</div>
                    <div class="t">Hardbound Thesis Submission</div>
                </td>
            </tr>
        </table>

        <h2>STUDENT'S DETAILS</h2>

        <table class="det">
            <tr>
                <td class="l">Full Name :</td>
                <td>
                    <table class="grid">
                        @foreach ($name as $row)<tr>@foreach ($row as $ch)<td>{{ $ch }}</td>@endforeach</tr>@endforeach
                    </table>
                </td>
            </tr>
            <tr><td colspan="2" style="height: 8px;"></td></tr>
            <tr>
                <td class="l">Matrix No:</td>
                <td>
                    <table class="grid"><tr>@foreach ($matric as $ch)<td>{{ $ch }}</td>@endforeach</tr></table>
                    &nbsp;&nbsp;&nbsp;Country:&nbsp;
                    <table class="grid"><tr>@foreach ($country as $ch)<td>{{ $ch }}</td>@endforeach</tr></table>
                    &nbsp;&nbsp;Intake:&nbsp;
                    <table class="grid"><tr>@foreach ($intake as $ch)<td>{{ $ch }}</td>@endforeach</tr></table>
                </td>
            </tr>
            @if ($copy === 'ORIGINAL')
                <tr>
                    <td class="l">Passport/IC No:</td>
                    <td>
                        <table class="det" style="width: 100%;"><tr>
                            <td class="line" style="width: 42%;"></td>
                            <td style="width: 16%; text-align: center; white-space: nowrap;">Date of Birth:</td>
                            <td class="line"></td>
                        </tr></table>
                    </td>
                </tr>
                <tr><td class="l">Programme:</td><td class="line">{{ $student->programme }}</td></tr>
            @else
                <tr>
                    <td class="l">Programme:</td>
                    <td>
                        <table class="det" style="width: 100%;"><tr>
                            <td class="line" style="width: 42%;">{{ $student->programme }}</td>
                            <td style="width: 16%; text-align: center; white-space: nowrap;">Date of Birth:</td>
                            <td class="line"></td>
                        </tr></table>
                    </td>
                </tr>
            @endif
            <tr><td class="l">Title of Thesis:</td><td class="line"></td></tr>
            <tr><td class="l hint">(Not more than</td><td class="line"></td></tr>
            <tr><td class="l hint">15 words)</td><td class="line"></td></tr>
        </table>

        <p class="cert">
            I, the abovenamed, hereby certify that I have submitted 1 CD and two (2) copies of my corrected thesis
            titled as above, to the Centre of Graduate Studies, Universiti Teknologi PETRONAS. I also certify that the
            theses were prepared according to the Guidelines provided by the University.
        </p>

        <table class="sig">
            <tr>
                <td style="width: 55%; padding-right: 60px;">Signature:<div class="sigline"></div></td>
                <td>Dated:<div class="sigline"></div></td>
            </tr>
        </table>

        <div class="rule"></div>

        <div class="office">FOR OFFICE USE ONLY</div>
        <p class="cert" style="margin-top: 0;">
            I, <span class="blank"></span> hereby certify that I have received 1 CD and two (2)
            copies of the thesis titled as above, and that the theses were prepared according to the Guidelines
            provided by the University.
        </p>

        <table class="sig">
            <tr>
                <td style="width: 55%; padding-right: 60px;">Signature and Official Stamp:<div class="sigline"></div></td>
                <td>Dated:<div class="sigline"></div></td>
            </tr>
        </table>
    </div>
</div>
@endforeach
</body>
</html>
