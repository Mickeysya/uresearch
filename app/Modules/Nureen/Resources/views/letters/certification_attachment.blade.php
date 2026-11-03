{{--
    CGS's "Attachment for Request for GA/GRA Certification Letter -- for
    verification by the course owner", recreated for Dompdf from
    docs/forms/GA_GRA_Certification_form_-_Attachment.pdf and pre-filled by
    LetterRenderer::certificationAttachment(). Same page, same order, same
    five-row table; only the first row is filled in. SIGNATURE and STAMP
    are left blank for the course owner.

    A self-contained page rather than letters/layout: this is CGS's form,
    not a UTP letter, so it carries no letterhead or reference.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attachment for Request for GA/GRA Certification Letter</title>
<style>
    @page { size: A4; margin: 30mm 20mm 22mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #000; }
    h1 { margin: 0; text-align: center; font-size: 12.5pt; font-weight: bold; }
    .sub { margin: 2px 0 0; text-align: center; font-size: 9.5pt; font-style: italic; }
    .facts { margin: 40px 0 0; border-collapse: collapse; table-layout: auto; width: auto; }
    .facts td { border: 0; height: auto; padding: 0 12px 12px 0; font-size: 9.5pt; vertical-align: baseline; }
    .facts td.label { font-weight: bold; padding-right: 14px; }
    .confirm { margin: 18px 0 8px; font-size: 9.5pt; line-height: 1.35; }
    table.courses { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th { background: #0070C0; color: #fff; font-size: 9pt; font-weight: bold; padding: 3px 4px; border: 1px solid #333; text-align: center; }
    .courses td { border: 1px solid #333; height: 42px; padding: 4px 5px; font-size: 9pt; vertical-align: middle; word-wrap: break-word; }
    .courses td.code { text-align: center; }
    /* As on CGS's form: the words above a full-width rule. */
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; border-bottom: 1px solid #333; padding-bottom: 6px; text-align: right; font-style: italic; font-size: 10pt; }
</style>
</head>
<body>
    <h1>Attachment for Request for GA/GRA Certification Letter</h1>
    <p class="sub">-For verification by the course owner-</p>

    <table class="facts">
        <tr><td class="label">Name:</td><td>{{ $name }}@if ($matric) ({{ $matric }})@endif</td></tr>
        <tr><td class="label">Programme:</td><td>{{ $programme }}</td></tr>
        <tr><td class="label">Date:</td><td>{{ $date->format('j F Y') }}</td></tr>
    </table>

    <p class="confirm">
        I hereby confirm that the below name is a student who had worked under my supervision for a particular
        {{ $isGra ? 'research project' : 'course' }} as per mentioned below.
    </p>

    {{-- Column widths on the header cells: Dompdf ignores <colgroup>. --}}
    <table class="courses">
        <thead>
            <tr>
                <th style="width: 12%">CODE</th>
                <th style="width: 27%">COURSE</th>
                <th style="width: 26%">COURSE OWNER</th>
                <th style="width: 17.5%">SIGNATURE</th>
                <th style="width: 17.5%">STAMP</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="code">{{ $row['code'] }}</td>
                    <td>{{ $row['course'] }}</td>
                    <td>{{ $row['owner'] }}</td>
                    <td></td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">Centre for Graduate Studies</div>
</body>
</html>
