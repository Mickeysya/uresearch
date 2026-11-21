{{-- One-page sample supporting documents for `nureen:demo-fresh` -- see
     Support\Demo\DemoDocumentFactory. Self-contained for Dompdf. Every page
     says in its footer that it is a sample. --}}
@php
    $name = $student->name;
    $matric = $student->matric_no ?? '—';
    $dept = $student->department ?? '—';
    $programme = $extra['programme'] ?? ($student->programme ?? 'Postgraduate Programme');
    $svName = $supervisor?->name ?? 'Main Supervisor';
    $date = $today->format('j F Y');
    $ic = $extra['identity_number'] ?? '000000-00-0000';
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}: {{ $name }}</title>
    <style>
        @page { margin: 2cm 2.2cm 2.4cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #222; line-height: 1.45; }
        .head { border-bottom: 1.5pt solid #0b3d6e; padding-bottom: 8pt; margin-bottom: 14pt; }
        .head img { height: 42pt; float: left; margin-right: 10pt; }
        .head .org { font-weight: bold; font-size: 11.5pt; color: #0b3d6e; }
        .head .sub { font-size: 8.5pt; color: #555; }
        .clear { clear: both; }
        h1 { font-size: 13pt; margin: 4pt 0 10pt; color: #0b3d6e; }
        h2 { font-size: 10.5pt; margin: 12pt 0 4pt; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 4pt 6pt; border: 0.5pt solid #bbb; vertical-align: top; text-align: left; }
        th { background: #eef2f7; }
        table.plain td { border: none; padding: 2pt 4pt 2pt 0; }
        td.k { width: 34%; color: #555; }
        .ref { color: #555; font-size: 9pt; }
        .sign { margin-top: 26pt; }
        .sign .gap { height: 34pt; }
        .sign .line { border-top: 0.5pt solid #444; width: 45%; padding-top: 2pt; }
        .card { border: 1pt solid #666; border-radius: 8pt; padding: 12pt; width: 70%; margin: 10pt auto; background: #f6f8fb; }
        .stamp { color: #9a1c1c; border: 1.5pt solid #9a1c1c; display: inline-block; padding: 3pt 8pt; font-weight: bold; font-size: 9pt; transform: rotate(-4deg); }
        .foot { position: fixed; bottom: -1.5cm; left: 0; right: 0; font-size: 7.5pt; color: #888; text-align: center; border-top: 0.5pt solid #ddd; padding-top: 3pt; }
    </style>
</head>
<body>
<div class="foot">Sample document generated for the UResearch 2.0 demonstration on {{ $date }} for {{ $name }} ({{ $matric }}). Not an official record.</div>

@switch($kind)

@case('supervisor_letter')
    <div class="head">@if($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="org">Universiti Teknologi PETRONAS</div>
        <div class="sub">Department of {{ $dept }} · 32610 Seri Iskandar, Perak Darul Ridzuan</div><div class="clear"></div></div>
    <p class="ref">{{ $date }}</p>
    <p>The Senior Director<br>Centre for Graduate Studies<br>Universiti Teknologi PETRONAS</p>
    <p>Dear Sir/Madam,</p>
    <h1>{{ $extra['subject'] ?? 'Letter of Support' }}: {{ strtoupper($name) }} ({{ $matric }})</h1>
    <p>I am the Main Supervisor of {{ $name }} (Matric No. {{ $matric }}), a {{ $programme }} candidate in the Department of {{ $dept }}.
        {{ $extra['body'] ?? 'I write in support of the student\'s application.' }}</p>
    <p>{{ $name }} has made steady progress on the research, attends weekly supervision meetings, and has met the milestones
        agreed for this stage of candidature. I am satisfied that the request is justified and I support it without reservation.</p>
    <p>Please do not hesitate to contact me should you require further information.</p>
    <div class="sign">Yours faithfully,<div class="gap"></div><div class="line">{{ $svName }}<br>Main Supervisor, Department of {{ $dept }}<br>{{ $supervisor?->email }}</div></div>
    @break

@case('admission_offer')
    <div class="head">@if($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="org">Centre for Graduate Studies</div>
        <div class="sub">Universiti Teknologi PETRONAS · 32610 Seri Iskandar, Perak Darul Ridzuan</div><div class="clear"></div></div>
    <p class="ref">Ref: UTP/CGS/ADM/{{ $matric }} · {{ ($extra['admission_date'] ?? $today)->copy()->subWeeks(6)->format('j F Y') }}</p>
    <p>{{ $name }}<br>Matric No. {{ $matric }}</p>
    <h1>Offer of Admission and Graduate Assistantship</h1>
    <p>We are pleased to offer you a place in the following programme, and an appointment under the Graduate Assistantship (GA) scheme:</p>
    <table class="plain">
        <tr><td class="k">Programme</td><td>{{ $programme }}</td></tr>
        <tr><td class="k">Department</td><td>{{ $dept }}</td></tr>
        <tr><td class="k">Mode of study</td><td>Full-time, by research</td></tr>
        <tr><td class="k">Date of admission</td><td>{{ ($extra['admission_date'] ?? $today)->format('j F Y') }}</td></tr>
        <tr><td class="k">Main Supervisor</td><td>{{ $svName }}</td></tr>
        <tr><td class="k">GA appointment</td><td>{{ $extra['ga_period'] ?? 'As stated in your appointment letter' }}</td></tr>
    </table>
    <p>Please confirm your acceptance through the student portal within fourteen (14) days. This offer is subject to the University's
        postgraduate regulations and the Terms and Conditions of the GA scheme.</p>
    <div class="sign">Yours sincerely,<div class="gap"></div><div class="line">Senior Director<br>Centre for Graduate Studies</div></div>
    <p style="margin-top:14pt">ACCEPTED: <span class="stamp">ACCEPTED {{ ($extra['admission_date'] ?? $today)->copy()->subWeeks(4)->format('d/m/Y') }}</span></p>
    @break

@case('identity')
    <h1>Copy of MyKad (Identity Card)</h1>
    <p>Submitted by {{ $name }} ({{ $matric }}) in support of a UResearch application.</p>
    <div class="card">
        <table class="plain">
            <tr><td colspan="2" style="font-weight:bold; color:#0b3d6e">MALAYSIA · KAD PENGENALAN</td></tr>
            <tr><td class="k">Nama / Name</td><td>{{ strtoupper($name) }}</td></tr>
            <tr><td class="k">No. Kad Pengenalan</td><td>{{ $ic }}</td></tr>
            <tr><td class="k">Warganegara</td><td>WARGANEGARA</td></tr>
            <tr><td class="k">Alamat</td><td>No. 12, Jalan Universiti 3,<br>32610 Seri Iskandar, Perak</td></tr>
        </table>
    </div>
    <p>I certify that this is a true copy of the original document.</p>
    <div class="sign"><div class="gap"></div><div class="line">{{ $name }}<br>{{ $date }}</div></div>
    <p><span class="stamp">CERTIFIED TRUE COPY</span></p>
    @break

@case('acceptance_proof')
    <div class="head"><div class="org">{{ $extra['journal'] ?? 'Journal of Engineering Research' }}</div>
        <div class="sub">Editorial Office · Manuscript tracking system</div></div>
    <p class="ref">{{ $today->copy()->subMonths(2)->format('j F Y') }}</p>
    <p>Dear {{ $name }},</p>
    <h1>Notification of Acceptance: Manuscript {{ $extra['manuscript'] ?? 'JER-2026-0417' }}</h1>
    <p>I am pleased to inform you that your manuscript, <strong>“{{ $extra['paper_title'] ?? 'Untitled' }}”</strong>, co-authored with
        {{ $svName }}, has been accepted for publication in <em>{{ $extra['journal'] ?? 'the journal' }}</em> following peer review.</p>
    <table class="plain">
        <tr><td class="k">Indexing</td><td>{{ $extra['indexing'] ?? 'Web of Science (SCIE), Q2' }}</td></tr>
        <tr><td class="k">Corresponding author</td><td>{{ $name }}, Universiti Teknologi PETRONAS</td></tr>
        <tr><td class="k">Expected issue</td><td>{{ $today->copy()->addMonths(3)->format('F Y') }}</td></tr>
    </table>
    <p>The production team will contact you with the proofs. Thank you for your contribution.</p>
    <div class="sign">Sincerely,<div class="gap"></div><div class="line">Editor-in-Chief</div></div>
    @break

@case('research_proposal')
    <h1>Research Proposal Summary</h1>
    <table class="plain">
        <tr><td class="k">Candidate</td><td>{{ $name }} ({{ $matric }})</td></tr>
        <tr><td class="k">Programme / Department</td><td>{{ $programme }} · {{ $dept }}</td></tr>
        <tr><td class="k">Proposed Main Supervisor</td><td>{{ $svName }}</td></tr>
        <tr><td class="k">Working title</td><td><strong>{{ $extra['title'] ?? 'Research proposal' }}</strong></td></tr>
    </table>
    <h2>Problem statement</h2>
    <p>{{ $extra['problem'] ?? 'Existing approaches do not scale to the data volumes now routine in the field, and their accuracy degrades sharply under realistic noise.' }}</p>
    <h2>Objectives</h2>
    <ol><li>To review and benchmark the current state of the art.</li><li>To design and implement an improved method.</li><li>To validate the method on an industrial case study with the supervisor's research group.</li></ol>
    <h2>Milestones</h2>
    <table><tr><th>Milestone</th><th>Target</th></tr>
        <tr><td>Research Proposal Defence (RPD)</td><td>Month 6</td></tr><tr><td>First journal article submitted</td><td>Month 12</td></tr>
        <tr><td>Thesis submission</td><td>Month 24</td></tr></table>
    <div class="sign"><div class="gap"></div><div class="line">{{ $name }}<br>{{ $date }}</div></div>
    @break

@case('evidence_of_work')
    <div class="head">@if($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="org">Universiti Teknologi PETRONAS</div><div class="sub">Department of {{ $dept }}</div><div class="clear"></div></div>
    <h1>Evidence of Work Done: {{ $extra['type'] ?? 'GA' }} ({{ $extra['semester'] ?? 'May 2026' }})</h1>
    <table class="plain">
        <tr><td class="k">Name / Matric No.</td><td>{{ $name }} ({{ $matric }})</td></tr>
        <tr><td class="k">{{ ($extra['type'] ?? 'GA') === 'GRA' ? 'Project' : 'Course' }}</td><td>{{ $extra['course'] ?? '—' }}</td></tr>
        <tr><td class="k">Period</td><td>{{ $extra['period'] ?? '—' }}</td></tr>
    </table>
    <h2>Duties performed</h2>
    <table><tr><th>Weeks</th><th>Duty</th><th>Hours</th></tr>
        <tr><td>1–4</td><td>{{ ($extra['type'] ?? 'GA') === 'GRA' ? 'Literature review and data collection' : 'Tutorial classes (2 groups) and lab preparation' }}</td><td>32</td></tr>
        <tr><td>5–9</td><td>{{ ($extra['type'] ?? 'GA') === 'GRA' ? 'Experimental runs and analysis' : 'Laboratory sessions and marking of lab reports' }}</td><td>40</td></tr>
        <tr><td>10–14</td><td>{{ ($extra['type'] ?? 'GA') === 'GRA' ? 'Progress report and project meeting minutes' : 'Consultation hours and invigilation' }}</td><td>36</td></tr>
        <tr><th colspan="2">Total</th><th>108</th></tr></table>
    <p>I confirm the duties above were carried out satisfactorily.</p>
    <div class="sign"><div class="gap"></div><div class="line">{{ $extra['signed_by'] ?? $svName }}<br>{{ ($extra['type'] ?? 'GA') === 'GRA' ? 'Project Leader' : 'Course Owner' }}</div></div>
    @break

@case('justification_letter')
    <p class="ref">{{ $date }}</p>
    <p>Non-Executive Officer<br>Centre for Graduate Studies<br>Universiti Teknologi PETRONAS</p>
    <h1>Justification for Attendance Below 80%: {{ $name }} ({{ $matric }})</h1>
    <p>I write to explain my UTrace attendance for {{ $extra['period'] ?? 'recent weeks' }}. {{ $extra['reason'] ?? '' }}</p>
    <p>During these periods I continued my research work as shown in the attached Monthly Activity Breakdown, and my supervisor,
        {{ $svName }}, is aware of and supports this appeal. I respectfully request that the sessions concerned be excused.</p>
    <div class="sign">Yours sincerely,<div class="gap"></div><div class="line">{{ $name }}<br>{{ $programme }}, {{ $dept }}</div></div>
    <p style="margin-top:10pt">Acknowledged by supervisor: {{ $svName }}</p>
    @break

@case('activity_breakdown')
    <h1>Monthly Activity Breakdown: {{ $name }} ({{ $matric }})</h1>
    <p>{{ $programme }} · {{ $dept }} · Supervisor: {{ $svName }}</p>
    <table><tr><th>Date</th><th>Activity</th><th>Location</th></tr>
        @foreach ($extra['rows'] ?? [] as $row)
            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td></tr>
        @endforeach
    </table>
    <div class="sign">Verified by:<div class="gap"></div><div class="line">{{ $svName }}<br>Main Supervisor</div></div>
    @break

@case('resume')
    <h1>{{ strtoupper($name) }}</h1>
    <p>{{ $student->email }} · Matric No. {{ $matric }} · {{ $programme }}, {{ $dept }}</p>
    <h2>Education</h2>
    <table class="plain"><tr><td class="k">{{ $today->year - 3 }}–{{ $today->year - 1 }}</td><td>Bachelor of {{ $dept === 'Computing' ? 'Computer Science' : 'Engineering' }} (Hons), Universiti Teknologi PETRONAS, CGPA 3.62</td></tr>
        <tr><td class="k">{{ $today->year - 1 }}–present</td><td>{{ $programme }}, {{ $dept }}</td></tr></table>
    <h2>Experience</h2>
    <table class="plain"><tr><td class="k">Jun–Aug {{ $today->year - 2 }}</td><td>Industrial intern, PETRONAS Digital Sdn Bhd</td></tr>
        <tr><td class="k">{{ $today->year - 1 }}</td><td>Undergraduate tutor, {{ $dept }} foundation courses</td></tr></table>
    <h2>Skills</h2><p>Python, MATLAB, technical writing, laboratory supervision, data analysis.</p>
    @break

@case('transcript')
    <div class="head">@if($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="org">Universiti Teknologi PETRONAS Registry</div><div class="sub">Official Academic Transcript (copy)</div><div class="clear"></div></div>
    <table class="plain"><tr><td class="k">Name</td><td>{{ $name }}</td></tr><tr><td class="k">Student ID</td><td>{{ $matric }}</td></tr>
        <tr><td class="k">Programme</td><td>Bachelor of {{ $dept === 'Computing' ? 'Computer Science' : 'Engineering' }} (Hons)</td></tr></table>
    <table style="margin-top:8pt"><tr><th>Code</th><th>Course</th><th>Credit</th><th>Grade</th></tr>
        <tr><td>TEB1013</td><td>Structured Programming</td><td>3</td><td>A</td></tr><tr><td>TEB2023</td><td>Data Structures</td><td>3</td><td>A-</td></tr>
        <tr><td>TFB3013</td><td>Research Methodology</td><td>3</td><td>A</td></tr><tr><td>TFB4024</td><td>Final Year Project</td><td>4</td><td>A</td></tr>
        <tr><th colspan="3">Cumulative Grade Point Average (CGPA)</th><th>3.62</th></tr></table>
    <p class="ref" style="margin-top:8pt">Degree conferred with Second Class Honours (Upper Division).</p>
    @break

@case('interview_report')
    <h1>Graduate Assistantship Interview Report</h1>
    <table class="plain"><tr><td class="k">Candidate</td><td>{{ $name }} ({{ $matric }})</td></tr>
        <tr><td class="k">Department</td><td>{{ $dept }}</td></tr><tr><td class="k">Interview date</td><td>{{ $extra['date'] ?? $date }}</td></tr></table>
    <table style="margin-top:8pt"><tr><th>Criterion</th><th>Score (1–5)</th></tr>
        <tr><td>Subject knowledge</td><td>4</td></tr><tr><td>Communication</td><td>4</td></tr><tr><td>Teaching readiness</td><td>5</td></tr><tr><td>Commitment</td><td>4</td></tr></table>
    <p><strong>Result: PASS.</strong> The candidate is recommended for appointment as a Graduate Assistant.</p>
    <div class="sign"><div class="gap"></div><div class="line">{{ $extra['interviewer'] ?? 'Research Centre Officer' }}<br>Research Centre, {{ $dept }}</div></div>
    @break

@endswitch
</body>
</html>
