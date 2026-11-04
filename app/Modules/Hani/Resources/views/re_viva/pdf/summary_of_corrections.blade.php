{{--
    Summary of Corrections, rendered from the student's digital form by
    Hani\Support\SummaryOfCorrections. Comments are verbatim. To restyle,
    edit the <style> block only.

    Data: $summary, $student, $supervisor, $thesisTitle, $institution
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Summary of Corrections</title>
<style>
    @page { margin: 20mm 16mm; }
    body { font-family: "DejaVu Serif", serif; font-size: 9.5pt; line-height: 1.35; color: #111; }
    h1 { font-size: 13pt; text-align: center; text-transform: uppercase; margin: 0 0 4pt; }
    h2 { font-size: 10.5pt; margin: 14pt 0 4pt; }
    .sub { text-align: center; font-size: 9pt; color: #444; margin-bottom: 10pt; }
    table { width: 100%; border-collapse: collapse; }
    table.details td { padding: 2pt 6pt 2pt 0; vertical-align: top; }
    table.details td.label { width: 28%; font-weight: bold; }
    table.rows th, table.rows td { border: 0.5pt solid #888; padding: 4pt; vertical-align: top; }
    table.rows th { background: #f2f2f2; text-align: left; }
    .endorse { margin-top: 18pt; }
    .endorse td { padding: 14pt 6pt 2pt 0; }
    .footnote { margin-top: 12pt; font-size: 8pt; color: #555; }
</style>
</head>
<body>
    <h1>Summary of Corrections</h1>
    <div class="sub">Centre for Graduate Studies · {{ $institution }}</div>

    <table class="details">
        <tr><td class="label">Candidate</td><td>{{ $student->name }}@if ($student->matric_no) ({{ $student->matric_no }})@endif</td></tr>
        <tr><td class="label">Programme</td><td>{{ $student->programme ?: '-' }}</td></tr>
        <tr><td class="label">Thesis title</td><td>{{ $thesisTitle }}</td></tr>
        <tr><td class="label">Corrected thesis</td><td>{{ $summary->thesis_name ?: '-' }}@if ($summary->thesis_pages), {{ $summary->thesis_pages }} pages @endif</td></tr>
    </table>

    @foreach (\App\Modules\Hani\Models\ReVivaSummary::EXAMINERS as $n => $label)
        <h2>{{ $label }}</h2>
        <table class="rows">
            <tr><th style="width: 5%">No.</th><th style="width: 42%">Examiner's comment</th><th style="width: 38%">Action taken</th><th style="width: 15%">Updated pages</th></tr>
            @forelse ($summary->rowsFor($n) as $i => $row)
                <tr><td>{{ $i + 1 }}</td><td>{{ $row['comment'] }}</td><td>{{ $row['action'] }}</td><td>{{ $row['pages'] }}</td></tr>
            @empty
                <tr><td colspan="4">No comments.</td></tr>
            @endforelse
        </table>
    @endforeach

    <table class="endorse">
        <tr><td style="width: 50%">Endorsed by the supervisor: {{ $supervisor ?: '' }}</td><td>Signature and date: ______________________</td></tr>
    </table>

    <p class="footnote">Prepared in UResearch 2.0 on {{ ($summary->submitted_at ?? now())->format('j F Y') }}. Every comment was checked to have an action and pages within the corrected thesis.</p>
</body>
</html>
