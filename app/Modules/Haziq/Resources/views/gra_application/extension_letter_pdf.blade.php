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

    <p>Ref: UResearch/GRA-EXT/{{ $application->id }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">GRADUATE RESEARCH ASSISTANT (GRA) — EXTENSION APPROVAL LETTER</div>

    <div class="body">
        <p>This is to confirm that the Graduate Research Assistant (GRA) appointment of
            <b>{{ $student->name }}</b> has been extended, with the following particulars:</p>

        <table class="info">
            <tr><td class="label">Project Title</td><td>{{ $detail->project_title }}</td></tr>
            <tr><td class="label">New Service End Date</td><td>{{ $detail->service_end_date->format('j F Y') }}</td></tr>
            <tr><td class="label">Revised Monthly Allowance</td><td>RM {{ number_format($detail->proposed_allowance, 2) }}</td></tr>
        </table>

        <p>This extension does not alter your existing Stage Gate schedule, which remains anchored to your original admission date.</p>
        <p>This letter is generated through UResearch 2.0 and does not require a physical signature.</p>
    </div>

    <div class="signature">
        <p>Issued by:</p>
        <div class="line"></div>
        <p>Centre for Graduate Studies (CGS)<br>Universiti Teknologi PETRONAS</p>
    </div>
</body>
</html>
