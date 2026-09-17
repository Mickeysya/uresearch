@php
    /**
     * Confirmation of Correction to Thesis, UTP/CGS/017A (REV: June 2015),
     * generated from the student's details and re-issued at every approval
     * so each signatory's uploaded signature and the date are stamped into
     * their block as the form moves.
     *
     * $signatures is keyed by stage key ('supervisor', 'chair'); a stage
     * that has not signed yet has no entry. The Examiner block is always
     * left for a physical signature and official stamp -- examiners have
     * no login.
     */
    $boxes = function (?string $text, int $perRow, int $rows = 1): array {
        $chars = preg_split('//u', strtoupper((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        $chars = array_slice($chars, 0, $perRow * $rows);
        return array_chunk(array_pad($chars, $perRow * $rows, ''), $perRow);
    };

    $nameRows = $boxes($student?->name, 27, 2);
    $matricRow = $boxes($detail->matric_no, 7)[0];

    $signatories = [
        'supervisor' => 'SUPERVISOR:',
        'examiner'   => 'INTERNAL / EXTERNAL EXAMINER:',
        'chair'      => 'CHAIRMAN, VIVA VOCE EXAMINATION',
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Confirmation of Correction to Thesis: #{{ $application->id }}</title>
    <style>
        @page { margin: 12mm 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #000; line-height: 1.45; }
        table.top { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.top td { padding: 0; vertical-align: bottom; }
        table.top .green { font-size: 10px; }
        table.top .code { border: 1px solid #000; padding: 3px 12px; text-align: center; font-size: 9.5px; line-height: 1.3; display: inline-block; }
        .frame { border: 3px double #000; padding: 22px 26px 40px; }
        table.hdr { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
        table.hdr td { vertical-align: middle; padding: 0; }
        table.hdr td.logo { width: 90px; }
        table.hdr img { height: 82px; }
        table.hdr .u { font-size: 17px; font-weight: bold; text-align: center; white-space: nowrap; }
        table.hdr .c { font-size: 13px; font-weight: bold; text-align: center; }
        table.hdr .t { font-size: 13.5px; text-align: center; letter-spacing: 0.3px; margin-top: 4px; }
        h2 { font-size: 12px; font-weight: bold; margin: 0 0 10px; }
        table.det { border-collapse: collapse; width: 100%; }
        table.det td { padding: 5px 0; vertical-align: bottom; }
        table.det td.l { width: 96px; padding-right: 6px; }
        table.det td.line { border-bottom: 1px solid #000; }
        table.grid { border-collapse: collapse; }
        table.grid td { border: 1px solid #000; width: 19px; height: 20px; text-align: center; font-size: 10.5px; padding: 0; }
        .hint { font-style: italic; font-size: 9.5px; }
        .cert { margin: 16px 0 14px; }
        .cert .h { text-decoration: underline; margin-bottom: 4px; }
        table.sig { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.sig td { vertical-align: top; padding: 0; }
        table.sig .who { text-decoration: underline; }
        table.sig .lbl { display: block; }
        .sigline { position: relative; height: 46px; border-bottom: 1px solid #000; margin-top: 4px; }
        .sigline img { position: absolute; left: 4px; bottom: 2px; max-height: 44px; max-width: 95%; }
        .dateline { position: relative; height: 46px; border-bottom: 1px solid #000; margin-top: 4px; }
        .dateline span { position: absolute; left: 4px; bottom: 4px; }
        .foot { margin-top: 10px; font-size: 7.5px; color: #555; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td class="green">* PRINT ON GREEN PAPER</td>
            <td style="text-align: right;"><span class="code">UTP/CGS/017A<br>REV: June 2015</span></td>
        </tr>
    </table>

    <div class="frame">
        <table class="hdr">
            <tr>
                <td class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="UTP"></td>
                <td>
                    <div class="u">UNIVERSITI TEKNOLOGI PETRONAS</div>
                    <div class="c">CENTRE FOR GRADUATE STUDIES</div>
                    <div class="t">CONFIRMATION OF CORRECTION TO THESIS</div>
                </td>
            </tr>
        </table>

        <h2>STUDENT'S DETAILS</h2>

        <table class="det">
            <tr>
                <td class="l">Full Name :</td>
                <td>
                    <table class="grid">
                        @foreach ($nameRows as $row)
                            <tr>@foreach ($row as $ch)<td>{{ $ch }}</td>@endforeach</tr>
                        @endforeach
                    </table>
                </td>
            </tr>
            <tr><td colspan="2" style="height: 10px;"></td></tr>
            <tr>
                <td class="l">Matrix No:</td>
                <td>
                    <table class="grid"><tr>@foreach ($matricRow as $ch)<td>{{ $ch }}</td>@endforeach</tr></table>
                </td>
            </tr>
            <tr><td class="l">Programme:</td><td class="line">{{ $detail->programme }}</td></tr>
            <tr><td class="l">Viva Date :</td><td class="line">{{ $detail->viva_date?->format('j F Y') }}</td></tr>
            <tr><td class="l">Supervisor :</td><td class="line">{{ $detail->supervisor_name }}</td></tr>
            <tr><td class="l">Co-Supervisor :</td><td class="line">{{ $detail->co_supervisor_name }}</td></tr>
            <tr><td class="l">Title of Thesis</td><td class="line">{{ $detail->thesis_title }}</td></tr>
            <tr><td class="l hint">(Not more than</td><td class="line"></td></tr>
            <tr><td class="l hint">15 words)</td><td class="line"></td></tr>
        </table>

        <div class="cert">
            <div class="h">CONFIRMATION OF CORRECTION TO THESIS</div>
            We hereby certify that we have reviewed the above thesis and therefore verify that the thesis has
            been corrected/amended as required.
        </div>

        @foreach ($signatories as $key => $who)
            @php($sig = $signatures[$key] ?? null)
            <table class="sig" style="margin-bottom: 14px;">
                <tr>
                    <td style="width: 60%; padding-right: 30px;">
                        <span class="who">{{ $who }}</span>
                        <span class="lbl">Signature and Official Stamp:</span>
                        <div class="sigline">
                            @if ($sig && $sig['image'])
                                <img src="{{ $sig['image'] }}" alt="Signature of {{ $sig['name'] }}">
                            @endif
                        </div>
                    </td>
                    <td>
                        <span class="lbl">&nbsp;</span>
                        <span class="lbl">Dated:</span>
                        <div class="dateline">
                            @if ($sig)<span>{{ $sig['date']?->format('j F Y') }}</span>@endif
                        </div>
                    </td>
                </tr>
            </table>
        @endforeach
    </div>

    <div class="foot">
        Generated by UResearch 2.0 on {{ $issuedAt->format('j F Y, g:ia') }} against Application #{{ $application->id }}.
        Supervisor and Chairman signatures are applied electronically by each approver's own account at the moment of
        approval and are recorded in the application's decision history. The Examiner block is for a physical signature.
    </div>
</body>
</html>
