@php
    /**
     * The Thesis Evaluation Report (UTP/PPS/024) as the examiner receives it:
     * the header is filled from the nomination, everything else is the blank
     * form they complete and return. One is generated per examiner.
     */
    $lines = fn (int $n) => str_repeat('<div class="dots"></div>', $n);

    $categories = [
        ['Pass with Distinction',
         'The candidate conferred on the Degree subject to minimal correction in spelling, grammar and syntax only.',
         'Student submitting the final thesis within 1 month'],
        ['Pass with Minor Correction',
         'The candidate be conferred the Degree subject to minor correction (re-formatting of chapters, revision of literature, improvement in the declaration of research objectives or statements, insertion of missing references, amendment of inaccurately cited reference, and other minor improvements including language) to the thesis.',
         'Student is given up to 3 months to submit the final thesis'],
        ['Pass with Major Correction',
         'The candidate be conferred the Degree subject to major correction which includes extensive revision of the entire thesis, major improvement in description methodology, statistical re-analysis of research data, removal of chapter (s), re-discussion of the results, improvements in language, excluding additional experimental works and/or data collection to the thesis.',
         'Student is given minimum 3 months and up to 6 months to submit the final thesis'],
        ['Re-examination',
         'The candidate is to re-submit the thesis to be re-examined after the candidate has made major correction which includes extensive re-writing to the entire thesis and to include additional experimental work, data collection, new discussions of further studies and to enhance contribution of the studies. The candidate may be required to attend the oral (viva voce) examination again*.<br>(*Examiners will determine the viva voce examination upon evaluation on the corrected thesis).',
         'Student is given minimum 6 months and up to 12 months to submit the thesis for re-examination'],
        ['Fail',
         'The candidate is not to be conferred the Degree and not allowed to resubmit the thesis for reexamination. The candidate is deemed to have failed.',
         ''],
    ];

    // [number, heading, prompt, dotted lines] grouped as they fall on the issued form's pages.
    $section1 = [
        [['1.1', 'Overall academic merit of the thesis', 'Please evaluate whether the student has composed a thesis that is clear, well-written and able to provide a sufficient and comprehensive study as well as the novelty of the research topic within the level of study.', 6],
         ['1.2', 'Thesis structure and composition', 'Please evaluate whether the student has constructed a thesis that is clear and structured with a sense of continuity in the overall composition.', 6],
         ['1.3', 'Strength of thesis', 'Please evaluate whether the student has constructed a thesis that is able to demonstrate the strength, breadth and depth of knowledge in the thesis PhD level of study.', 6]],
        [['1.4', 'Originality and creativity', 'Please evaluate whether the student has constructed a thesis that is able to demonstrate the originality of concept and execution with high creativity and an appropriate standard of literary, visual or graphical representation.', 6],
         ['1.5', 'Significance of the study', 'Please evaluate whether the student has demonstrated the significance of the study that is able to extend past research work, has potential of discovery and innovative solutions to support the research novelty and knowledge contribution.', 7],
         ['1.6', 'Clarity of the study', 'Please evaluate whether the student has constructed a thesis that is clear and well-defined and able to demonstrate justification of analysis, selection of parameters and research approach or design.', 7]],
        [['1.7', 'Scientific rigor and ethical conduct', 'Please evaluate whether the student has composed a thesis with evidence of scientific rigor and ethical conduct.', 6],
         ['1.8', 'Use of Language', 'Please evaluate whether the student has constructed a thesis with use of language that is clear and concise with very minimal grammatical errors and is able to express and explain the research ideas and findings.', 6]],
    ];

    $section2 = [
        [['2.1', 'Title of Thesis', 'Please evaluate whether the student has formulated a title that is accurate to reflect the undergoing research.', 4],
         ['2.2', 'Abstract', 'Please evaluate whether the student has produced an abstract that is accurate to describe the highlighted research problems and objectives, research methodology and design, summary of significant findings, contribution and novelty of the research study.', 6],
         ['2.3', 'Problem Statements', 'Please evaluate whether the student has devised problem statements that are clear, rational and has high impact within the PhD level of study.', 5]],
        [['2.4', 'Objectives of the study', 'Please evaluate whether the student has formulated and produced research objectives that are clear and significant in addressing all the highlighted research gaps within the research methodology/design through feasibility study and comprehensive data analyses.', 6],
         ['2.5', 'Scopes of the study', 'Please evaluate whether the student has devised a scope of study that is feasible and accurate to address the highlighted research problems and objectives, research methodology and design, summary of significant findings, contribution and novelty of the research study.', 6],
         ['2.6', 'Literature Review', 'Please evaluate whether the student has constructed literature review that are comprehensive, up-to-date with critical analysis to formulate the research problems and gaps, hypotheses of research, research objectives and research methodology/design.', 6]],
        [['2.7', 'Research Methodology/Design', 'Please evaluate whether the student has validated the research methodology or design that is appropriate to achieve specified objectives or research issues of the study and able to lead to novel and innovative solutions.', 8],
         ['2.8', 'Research results', 'Please evaluate whether the student has assessed all obtained research results in agreement with the outlined research objectives and is acceptable within the context of the research to support research novelty and knowledge contribution.', 7],
         ['2.9', 'Findings & Analysis', 'Please evaluate whether the student has validated and reported research findings and results that are critically analyzed, thoroughly discussed in relation to the fundamental theories or past research and relevant to the research objectives.', 6]],
        [['2.10', 'Research contribution', 'Please evaluate whether the student has created and produced research contribution that are clear, significant and has high impact on research novelty and knowledge contribution.', 4],
         ['2.11', 'Conclusion', 'Please evaluate whether the student has derived conclusions that are clear, significant and has impact on the achievement of the outlined research objectives within the PhD level of study.', 5],
         ['2.12', 'References', 'Please evaluate whether the student has compiled references that are relevant, up-to-date, significant and impactful while consistently following the right citation format throughout the thesis.', 6]],
    ];

    $components = [
        'Title of Thesis', 'Abstract', 'Problem Statement', 'Objectives of the study',
        'Scopes of the study', 'Literature Review', 'Research Methodology/ Design',
        'Research results', 'Findings & Analysis', 'Research contribution', 'Conclusion',
        'References', 'Section 1 (General Report; 1.1-1.8)',
    ];

    // Which PLO column each component ticks, per faculty, in $components order.
    $ploMap = [
        'Engineering' => ['cols' => [1, 2, 3, 6], 'ticks' => [2, 2, 2, 2, 1, 1, 1, 3, 3, 3, 3, 3, 6]],
        'Science' => ['cols' => [1, 2, 6, 7], 'ticks' => [1, 1, 1, 1, 2, 2, 2, 6, 6, 6, 6, 6, 7]],
        'Computing' => ['cols' => [1, 2, 3, 4], 'ticks' => [1, 1, 1, 1, 3, 3, 3, 2, 2, 2, 2, 2, 4]],
        'Business' => ['cols' => [1, 2, 5, 6], 'ticks' => [2, 2, 2, 2, 5, 5, 5, 1, 1, 1, 1, 1, 6]],
    ];

    $plos = [
        'Engineering' => [
            'Demonstrate continuing and advanced knowledge and have the capabilities to further develop or use these in new situation or multi-disciplinary contexts.',
            'Analyse and evaluate problems in the [engineering programme] discipline critically particularly in situations with limited information.',
            'Appraise available information and research evidence and apply it in the [engineering programme] context.',
            'Plan and perform research undertakings professionally, ethically, and responsibly.',
            'Report technical findings in both written and oral forms.',
            'Recognise the needs for continuing professional development.',
            'Evaluate problems and provide solutions through the application of appropriate tools and techniques in [engineering programme].',
        ],
        'Science' => [
            'Demonstrate mastery of knowledge in the science related fields.',
            'Apply analytical skills in the science related fields.',
            'Relate ideas to technological or societal issues in the science related fields.',
            'Conduct research with minimal supervision and adhere to ethical and professional standards.',
            'Develop leadership qualities through communicating and working effectively with peers and stakeholders.',
            'Evaluate problem and generate solutions to problems using advanced science knowledge and critical thinking skills.',
            'Analyse and Evaluate information for lifelong learning.',
        ],
        'Computing' => [
            'Apply and integrate knowledge concerning current research issues in computing and produce work that is at the forefront of developments in the domain of the [computing programme].',
            'Evaluate and analyse computing solutions in terms of their usability, efficiency, and effectiveness.',
            'Develop computing solutions and use necessary tools to analyse their performance.',
            'Appraise existing techniques of research and enquiry to acquire, interpret and extend, knowledge in computing.',
            'Communicate and function effectively in a group.',
            'Prepare, publish, and present technical material to a diverse audience.',
            'Demonstrate behaviour that is consistent with codes of professional ethics and responsibility.',
        ],
        'Business' => [
            'Critically evaluate literature and relate ideas to business or societal issues in management related fields.',
            'Apply appropriate research methods.',
            'Conduct research with minimal supervision and adhere to legal, ethical, and professional practices.',
            'Analyse data using qualitative and/or quantitative research tools in management related research area.',
            'Interpret and present research findings using scientific and critical thinking skills.',
            'Demonstrate mastery of knowledge in the management related fields.',
            'Develop leadership qualities through communicating and working effectively with peers and stakeholders.',
        ],
    ];

    $totalPages = 12;
    $page = 0;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Thesis Evaluation Report: {{ $examiner->examiner_name }}</title>
    <style>
        @page { margin: 14mm 18mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #000; line-height: 1.35; }
        p { margin: 0 0 6px; }
        .sheet { position: relative; height: 262mm; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .code { position: absolute; top: 0; right: 0; text-align: right; font-size: 9px; }
        .code .ver { border: 1px solid #000; padding: 2px 6px; margin-top: 3px; display: inline-block; }
        .logo { text-align: center; margin: 0 0 6px; }
        .logo img { height: 40px; }
        h1 { font-size: 12px; text-align: center; margin: 0 0 12px; line-height: 1.2; }
        h2 { font-size: 10px; margin: 0 0 8px; text-transform: uppercase; }
        h3 { font-size: 9.5px; margin: 10px 0 2px; }
        table.hdr { border-collapse: collapse; margin-bottom: 10px; }
        table.hdr td { padding: 1px 4px; vertical-align: top; }
        table.hdr td.l { width: 95px; }
        table.hdr td.v { border-bottom: 1px solid #000; min-width: 300px; }
        table.cat { width: 100%; border-collapse: collapse; }
        table.cat td { padding: 5px 4px; vertical-align: top; }
        table.cat td.num { width: 16px; }
        table.cat td.init { width: 78px; border-bottom: 1px solid #000; }
        table.cat td.note { width: 92px; font-size: 8.5px; padding-left: 8px; }
        table.cat th { text-align: left; font-size: 9px; padding: 2px 4px; }
        .dots { border-bottom: 1px dotted #000; height: 21px; }
        .scale { text-align: center; margin: 8px 0 4px; }
        .box { display: inline-block; width: 9px; height: 9px; border: 1px solid #000; vertical-align: middle; margin: 0 3px 0 12px; }
        .prompt { font-size: 9px; margin-bottom: 4px; }
        .foot { position: absolute; bottom: 0; left: 0; right: 0; font-size: 8.5px; }
        .foot .sig { display: flow-root; margin-bottom: 4px; }
        .foot .sig .r { float: right; }
        .foot .pg { display: flow-root; }
        .foot .pg .r { float: right; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; text-align: left; }
        table.grid th { font-weight: bold; }
        table.plo { width: 100%; border-collapse: collapse; font-size: 7.5px; }
        table.plo th, table.plo td { border: 1px solid #000; padding: 2px 3px; text-align: center; }
        table.plo td.c { text-align: left; }
        table.plo th.fac { background: #dfe6f2; font-size: 8px; }
        table.two { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
        table.two > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
        table.desc { width: 100%; border-collapse: collapse; font-size: 7.5px; }
        table.desc th, table.desc td { border: 1px solid #000; padding: 2px 4px; vertical-align: top; text-align: left; }
        table.desc th.fac { background: #dfe6f2; text-align: center; font-size: 8px; }
        table.desc td.k { width: 32px; font-weight: bold; text-align: center; }
    </style>
</head>
<body>

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/025<br><span class="ver">Ver: Oct 2025</span></div>
    <div class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="UTP"></div>
    <h1>THESIS EVALUATION REPORT<br>PHD</h1>

    <table class="hdr">
        <tr><td class="l">Candidate</td><td>:</td><td class="v">{{ $student?->name ?? '' }}</td></tr>
        <tr><td class="l">Student ID</td><td>:</td><td class="v">{{ $student?->matric_no ?? '' }}</td></tr>
        <tr><td class="l">Programme</td><td>:</td><td class="v">{{ $detail->candidate_programme }}</td></tr>
        <tr><td class="l">Title of Thesis</td><td>:</td><td class="v">{{ $detail->thesis_title }}</td></tr>
        <tr><td class="l">Examiner Name</td><td>:</td><td class="v">{{ $examiner->examiner_name }}</td></tr>
    </table>

    <table class="cat">
        <tr><th colspan="3">PLEASE INITIAL WHERE APPROPRIATE:</th><th>INITIAL</th><th>NOTE</th></tr>
        @foreach ($categories as $i => [$name, $desc, $note])
            <tr>
                <td class="num">{{ $i + 1 }}.</td>
                <td colspan="2"><u><b>{{ $name }}</b></u><br>{!! $desc !!}</td>
                <td class="init"></td>
                <td class="note">{{ $note }}</td>
            </tr>
        @endforeach
    </table>

    <p style="font-style: italic; margin-top: 10px;">
        Please state overleaf the grounds on which you base your recommendation, indicating where
        appropriate, the strengths and weaknesses of the thesis.
    </p>

    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/024 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/024</div>
    <p style="text-align: center; font-style: italic; margin-top: 40px;">Please see overleaf</p>
    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/023 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

@foreach ($section1 as $pageItems)
    @php($page++)
    <div class="sheet">
        <div class="code">UTP/PPS/024</div>
        @if ($loop->first) <h2>Section 1: General Report</h2> @endif
        @foreach ($pageItems as [$num, $heading, $prompt, $n])
            <h3>{{ $num }} {{ $heading }}</h3>
            <p class="prompt">{{ $prompt }}</p>
            {!! $lines($n) !!}
            <div class="scale">Poor
                @for ($k = 1; $k <= 5; $k++)<span class="box"></span> {{ $k }}@endfor
                &nbsp;&nbsp;&nbsp;Excellent
            </div>
        @endforeach
        <div class="foot">
            <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
            <div class="pg">UTP/PPS/023 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
        </div>
    </div>
@endforeach

@foreach ($section2 as $pageItems)
    @php($page++)
    <div class="sheet">
        <div class="code">UTP/PPS/024</div>
        @if ($loop->first) <h2>Section 2: Thesis Comments</h2> @endif
        @foreach ($pageItems as [$num, $heading, $prompt, $n])
            <h3>{{ $num }} {{ $heading }}</h3>
            <p class="prompt">{{ $prompt }}</p>
            {!! $lines($n) !!}
            <div class="scale">Poor
                @for ($k = 1; $k <= 5; $k++)<span class="box"></span> {{ $k }}@endfor
                &nbsp;&nbsp;&nbsp;Excellent
            </div>
        @endforeach
        <div class="foot">
            <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
            <div class="pg">UTP/PPS/023 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
        </div>
    </div>
@endforeach

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/024</div>
    <h2>Section 3: Corrections and Amendments</h2>
    {!! $lines(30) !!}
    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/023 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/025</div>
    <p style="margin-top: 14px;"><u><b>The Thesis is required to include the following major modifications and corrections</b></u></p>
    <p>(Applicable for category 4 only)</p>
    <table class="grid" style="margin-top: 8px;">
        <thead>
            <tr>
                <th style="width: 130px;">Major modifications and corrections</th>
                <th style="width: 40%;">Description</th>
                <th>Action to be taken by candidate</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['Additional experimental works', 'Additional data collection', 'Additional discussion on the results', 'Enhance contribution of the thesis', 'Others'] as $row)
                <tr style="height: 62px;"><td>{{ $row }}</td><td></td><td></td></tr>
            @endforeach
        </tbody>
    </table>
    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/024 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/025</div>
    <p style="text-align: center; font-weight: bold; margin: 14px 0 10px;">Assessment rubric vs Program Learning Outcome (PLO) Mapping</p>
    <table class="two">
        @foreach (array_chunk($ploMap, 2, true) as $pair)
            <tr>
                @foreach ($pair as $faculty => $map)
                    <td>
                        <table class="plo">
                            <tr><th class="fac" colspan="{{ count($map['cols']) + 1 }}">{{ $faculty }}</th></tr>
                            <tr>
                                <th class="c">Assessment Component</th>
                                @foreach ($map['cols'] as $col)<th>PLO {{ $col }}</th>@endforeach
                            </tr>
                            @foreach ($components as $i => $component)
                                <tr>
                                    <td class="c">{{ $component }}</td>
                                    @foreach ($map['cols'] as $col)
                                        <td>{{ $map['ticks'][$i] === $col ? '√' : '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                    </td>
                @endforeach
            </tr>
            <tr><td colspan="2" style="height: 10px;"></td></tr>
        @endforeach
    </table>
    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/024 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

@php($page++)
<div class="sheet">
    <div class="code">UTP/PPS/024</div>
    <p style="text-align: center; font-weight: bold; margin: 14px 0 10px;">Program Learning Outcomes</p>
    <table class="two">
        @foreach (array_chunk($plos, 2, true) as $pair)
            <tr>
                @foreach ($pair as $faculty => $items)
                    <td>
                        <table class="desc">
                            <tr><th class="fac" colspan="2">{{ $faculty }}</th></tr>
                            @foreach ($items as $i => $text)
                                <tr><td class="k">PLO {{ $i + 1 }}</td><td>{{ $text }}</td></tr>
                            @endforeach
                        </table>
                    </td>
                @endforeach
            </tr>
            <tr><td colspan="2" style="height: 10px;"></td></tr>
        @endforeach
    </table>
    <div class="foot">
        <div class="sig">Signed: _______________________ <span class="r">Date: ________________</span></div>
        <div class="pg">UTP/PPS/023 <span class="r">Page <b>{{ $page }}</b> of {{ $totalPages }}</span></div>
    </div>
</div>

</body>
</html>
