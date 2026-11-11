<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>UTP/CGS/024</title>
{{-- Thesis Re-evaluation Report: exactly 12 pages, broken by hand. --}}
<style>
    @page { margin: 18mm 20mm 22mm 20mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.3; color: #000; }
    p { margin: 0 0 6pt; }
    .head { position: fixed; top: -11mm; right: 0; font-size: 8pt; }
    .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; }
    .foot table { width: 100%; }
    .foot .n:after { content: "Page " counter(page) " of 12"; }
    .page { page-break-after: always; }
    .last { page-break-after: auto; }
    .ver { border: 1px solid #000; padding: 1pt 4pt; display: inline-block; }
    .top { text-align: right; font-size: 8.5pt; }
    h1 { text-align: center; font-size: 12pt; margin: 4pt 0 10pt; }
    h2 { font-size: 10.5pt; margin: 0 0 8pt; }
    h3 { font-size: 9.5pt; margin: 0 0 3pt; }
    .logo { text-align: center; } .logo img { height: 18mm; }
    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 8pt; } table.rows td { vertical-align: top; padding: 1.5pt 0; }
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 8pt; font-size: 8.3pt; } table.grid th, table.grid td { border: 1px solid #000; padding: 3pt; vertical-align: top; }
    .dots { border-bottom: 1px dotted #000; height: 15pt; }
    .item { margin-bottom: 14pt; }
    .rate { margin-top: 5pt; }
    .plos { width: 48%; display: inline-block; vertical-align: top; margin-right: 1%; }
    .plos table { font-size: 7pt; } .plos td, .plos th { padding: 1pt 2pt; text-align: center; } .plos td:first-child { text-align: left; }
</style></head><body>
<div class="foot"><table><tr><td>Signed: ____________</td><td>Date: ________</td><td style="text-align:right"><span class="n"></span></td></tr></table></div>
@php
    $word = $phd ? 'DOCTORAL' : 'MASTER';
    $options = [
        ['1. Pass', 'The candidate be conferred the Degree subject to minimal correction in spelling, grammar and syntax only.', 'Student to submit the final thesis within 1 month'],
        ['2. Pass with Minor Correction', 'The candidate has performed necessary corrections. The candidate be conferred the Degree subject to minor correction (re-formatting of chapters, revision of literature, improvement in the declaration of research objectives or statements, insertion of missing references, amendment of inaccurately cited reference, and other minor improvements including language) to the thesis.', 'Student is given up to 3 months to submit the final thesis'],
        ['3. Pass with Major Correction', 'The candidate has performed necessary corrections. The candidate be conferred the Degree subject to major correction which includes extensive revision of the entire thesis, major improvement in description methodology, statistical re-analysis of research data, removal of chapter (s), re-discussion of the results, improvements in language, excluding additional experimental works and/or data collection to the thesis.', 'Student is given minimum 3 months and up to 6 months to submit the final thesis'],
        ['4. Fail', 'The candidate has not performed necessary corrections up to the requirement. The candidate is not allowed to resubmit the thesis for reexamination. The candidate is deemed to have failed.', 'The candidate is required to attend the oral (viva voce) examination again (YES / NO)'],
    ];
    $sections = [
        ['SECTION 1: GENERAL REPORT', array_chunk([['1.1','Overall academic merit of the thesis','Please evaluate whether the student has composed a thesis that is clear, well-written and able to provide a sufficient and comprehensive study of the research topic within the level of study'],['1.2','Thesis structure and composition',''],['1.3','Strength of thesis',''],['1.4','Originality and creativity',''],['1.5','Significance of the study',''],['1.6','Clarity of the study',''],['1.7','Ethical conduct',''],['1.8','Use of Language','']], 3)],
        ['SECTION 2: THESIS COMMENTS', array_chunk([['2.1','Title of thesis',''],['2.2','Abstract',''],['2.3','Problem Statements',''],['2.4','Objectives of the study',''],['2.5','Scopes of the study',''],['2.6','Literature Review',''],['2.7','Research Methodology/Design',''],['2.8','Research results',''],['2.9','Findings & Analysis',''],['2.10','Research contribution',''],['2.11','Conclusion',''],['2.12','References','']], 3)],
    ];
    $rows = ['Title of Thesis', 'Abstract', 'Problem Statement', 'Objectives of the study', 'Scopes of the study', 'Literature Review', 'Research Methodology/Design', 'Research results', 'Findings & Analysis', 'Research contribution', 'Conclusion', 'References', 'Section 1 (General Report; 1.1–1.8)'];
    $mapping = ['Engineering' => [1, 2, 6, 7], 'Science' => [1, 2, 6, 7], 'Computing' => [1, 2, 3, 6], 'Business' => [2, 4, 5, 6]];
    $group = fn ($i) => $i <= 3 ? 0 : ($i <= 6 ? 1 : ($i <= 11 ? 2 : 3));
    $plo = fn ($faculty, $i) => $faculty === 'Business' ? [6, 2, 4, 5][$group($i)] : $mapping[$faculty][$group($i)];
@endphp
<div class="page">
    <div class="top">UTP/CGS/024<br><span class="ver">Ver: July 2021</span></div>
    <div class="logo"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/UTP_logo.png'))) }}" alt="UTP"></div>
    <h1>THESIS RE-EVALUATION REPORT<br>{{ $word }}</h1>
    <table class="rows">
        @foreach (['Candidate' => $student->name, 'Student ID' => $student->matric_no, 'Programme' => $degreeName, 'Title of Thesis' => $thesisTitle, 'Examiner Name' => $examinerName] as $l => $v)
            <tr><td style="width:32mm">{{ $l }}</td><td style="width:4mm">:</td><td>{{ $v }}</td></tr>
        @endforeach
    </table>
    <p><b>PLEASE INITIAL WHERE APPROPRIATE:</b></p>
    <table class="grid"><thead><tr><th style="width:15mm">INITIAL</th><th>RECOMMENDATION</th><th style="width:38mm">NOTE</th></tr></thead><tbody>
        @foreach ($options as [$title, $text, $note])<tr><td></td><td><b>{{ $title }}</b><br>{{ $text }}</td><td>{{ $note }}</td></tr>@endforeach
    </tbody></table>
</div>
<div class="head">UTP/CGS/024</div>
@foreach ($sections as [$heading, $pages])
    @foreach ($pages as $p => $items)
        <div class="page">
            @if ($p === 0)<h2>{{ $heading }}</h2>@endif
            @foreach ($items as [$no, $title, $guide])
                <div class="item">
                    <h3>{{ $no }} {{ $title }}</h3>
                    @if ($guide)<p>{{ $guide }}</p>@endif
                    @for ($i = 0; $i < 6; $i++)<div class="dots"></div>@endfor
                    <div class="rate">Poor &nbsp; 1 &nbsp; 2 &nbsp; 3 &nbsp; 4 &nbsp; 5 &nbsp; Excellent</div>
                </div>
            @endforeach
        </div>
    @endforeach
@endforeach
<div class="page"><h2>SECTION 3: CORRECTIONS AND AMENDMENTS</h2>@for ($i = 0; $i < 36; $i++)<div class="dots"></div>@endfor</div>
<div class="page">
    <p><b>The Thesis is required to include the following major modifications and corrections (Applicable for category 4 only)</b></p>
    <table class="grid"><thead><tr><th>Major modifications and corrections</th><th>Description</th><th>Action to be taken by candidate</th></tr></thead><tbody>
        @foreach (['Additional experimental works', 'Additional data collection', 'Additional discussion on the results', 'Enhance contribution of the thesis', 'Others'] as $row)<tr><td style="height:70pt">{{ $row }}</td><td></td><td></td></tr>@endforeach
    </tbody></table>
</div>
<div class="page"><h2>Assessment rubric vs Program Learning Outcome (PLO) Mapping</h2>
    @foreach ($mapping as $faculty => $columns)
        @php $columns = $faculty === 'Business' ? [2, 4, 5, 6] : $columns; @endphp
        <div class="plos"><p><b>{{ $faculty }}</b></p><table class="grid"><thead><tr><th></th>@foreach ($columns as $c)<th>PLO {{ $c }}</th>@endforeach</tr></thead><tbody>
            @foreach ($rows as $i => $row)<tr><td>{{ $row }}</td>@foreach ($columns as $c)<td style="font-family:'DejaVu Sans'">{{ $plo($faculty, $i) === $c ? '✓' : '' }}</td>@endforeach</tr>@endforeach
        </tbody></table></div>
    @endforeach
</div>
<div class="last"><h2>Program Learning Outcomes</h2>
    @foreach ($plos as $faculty => $list)<p><b>{{ $faculty }}</b></p>@foreach ($list as $n => $text)<p>PLO {{ $n }}: {{ $text }}</p>@endforeach @endforeach
</div>
</body></html>
