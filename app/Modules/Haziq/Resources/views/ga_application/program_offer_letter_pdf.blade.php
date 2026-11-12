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

    <p>Ref: UResearch/GA-PROG/{{ $application->id }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">GRADUATE ASSISTANT (GA): PROGRAM OFFER LETTER</div>

    <div class="body">
        <p>This confirms that <b>{{ $student->name }}</b> (Student ID: {{ $student->matric_no ?? '—' }})
            has been admitted into the Graduate Assistant (GA) Scheme for the programme
            <b>{{ $profile->programme }}</b> ({{ strtoupper($profile->level_of_study) }}),
            with admission date <b>{{ $profile->admission_date->format('j F Y') }}</b>.</p>

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
