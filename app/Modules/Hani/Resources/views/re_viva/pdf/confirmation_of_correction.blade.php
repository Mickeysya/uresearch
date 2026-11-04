{{--
    Confirmation of Correction: pre-filled for examiners 1 and 2 and the
    chairman to sign that the corrections were made. Rendered by
    Hani\Support\ReVivaCorrections with Dompdf; staff-only (names the examiners).

    To restyle, edit the <style> block only.

    Data: $student, $supervisor, $programme, $degreeName, $thesisTitle,
    $examiners (list of [role, name, place]), $chairman, $round, $levelLabel,
    $acceptedAt, $institution
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Confirmation of Correction</title>
<style>
    @page { margin: 24mm 20mm; }
    body { font-family: "DejaVu Serif", serif; font-size: 10.5pt; line-height: 1.4; color: #111; }
    h1 { font-size: 13pt; text-align: center; text-transform: uppercase; margin: 0 0 4pt; }
    .sub { text-align: center; font-size: 9.5pt; color: #444; margin-bottom: 14pt; }
    table.details { width: 100%; border-collapse: collapse; margin-bottom: 14pt; }
    table.details td { border: 0.5pt solid #888; padding: 4pt 6pt; vertical-align: top; }
    table.details td.label { width: 34%; font-weight: bold; background: #f2f2f2; }
    .statement { margin: 10pt 0 14pt; }
    table.signatures { width: 100%; border-collapse: collapse; }
    table.signatures td { border: 0.5pt solid #888; padding: 6pt; vertical-align: top; height: 58pt; }
    table.signatures th { border: 0.5pt solid #888; padding: 4pt 6pt; background: #f2f2f2; text-align: left; }
    .footnote { margin-top: 14pt; font-size: 8.5pt; color: #555; }
</style>
</head>
<body>
    <h1>Confirmation of Correction</h1>
    <div class="sub">Centre for Graduate Studies · {{ $institution }}</div>

    <table class="details">
        <tr><td class="label">Candidate</td><td>{{ $student->name }}</td></tr>
        <tr><td class="label">Matric number</td><td>{{ $student->matric_no ?: '-' }}</td></tr>
        <tr><td class="label">Degree / programme</td><td>{{ $degreeName }} · {{ $programme }}</td></tr>
        <tr><td class="label">Department</td><td>{{ $student->department ?: '-' }}</td></tr>
        <tr><td class="label">Supervisor</td><td>{{ $supervisor ?: '-' }}</td></tr>
        <tr><td class="label">Thesis title</td><td>{{ $thesisTitle }}</td></tr>
        <tr><td class="label">Re-viva</td><td>Round {{ $round }}: {{ $levelLabel }}</td></tr>
    </table>

    <p class="statement">We confirm that the candidate has made the corrections required by the examiners to the thesis named above, and that the final thesis may be accepted.</p>

    <table class="signatures">
        <tr><th style="width: 22%">Role</th><th style="width: 38%">Name</th><th style="width: 25%">Signature</th><th style="width: 15%">Date</th></tr>
        @foreach ($examiners as [$role, $name, $place])
            <tr>
                <td>{{ $role }}</td>
                <td>{{ $name }}@if ($place)<br><span style="font-size: 9pt; color: #444;">{{ $place }}</span>@endif</td>
                <td></td>
                <td></td>
            </tr>
        @endforeach
    </table>

    <p class="footnote">Pre-filled by UResearch 2.0 when the Academic Executive accepted the corrections on {{ $acceptedAt->format('j F Y') }}. Return the signed form to the Centre for Graduate Studies.</p>
</body>
</html>
