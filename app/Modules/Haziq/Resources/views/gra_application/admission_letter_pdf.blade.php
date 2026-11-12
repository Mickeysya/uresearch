<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('haziq::pdf._letter_style')
</head>
<body>
    <div class="letterhead">
        <h1>Universiti Teknologi PETRONAS</h1>
        <h2>Centre for Graduate Studies (CGS)</h2>
    </div>

    <p>Ref: UResearch/GRA-ADM/{{ $application->id }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">GRADUATE RESEARCH ASSISTANT (GRA): ADMISSION LETTER</div>

    <div class="body">
        <p>This is to confirm that <b>{{ $student->name }}</b>
            (Student ID: {{ $student->matric_no ?? '—' }}) has been admitted into the
            Graduate Research Assistant (GRA) Scheme, with the following particulars:</p>

        <table class="info">
            <tr><td class="label">Admission Date</td><td>{{ $profile->admission_date->format('j F Y') }}</td></tr>
            <tr><td class="label">Level of Study</td><td>{{ strtoupper($profile->level_of_study) }}</td></tr>
            <tr><td class="label">Programme</td><td>{{ $profile->programme }}</td></tr>
            <tr><td class="label">Maximum GRA Duration</td><td>{{ $profile->level_of_study === 'phd' ? '42 months' : '24 months' }}, counted from the admission date above</td></tr>
        </table>

        <p>Your Stage Gate schedule for this appointment has been generated from the admission date above and is available to view in UResearch 2.0.</p>
        <p>This letter is generated through UResearch 2.0 and does not require a physical signature.</p>
    </div>

    <div class="signature">
        <p>Issued by:</p>
        <div class="line"></div>
        <p>Centre for Graduate Studies (CGS)<br>Universiti Teknologi PETRONAS</p>
    </div>
</body>
</html>
