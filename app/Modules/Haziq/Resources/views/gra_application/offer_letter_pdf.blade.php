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

    <p>Ref: UResearch/GRA-OFFER/{{ $application->id }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">GRADUATE RESEARCH ASSISTANT (GRA) — OFFER LETTER</div>

    <div class="body">
        <p>Dear <b>{{ $student->name }}</b>,</p>
        <p>We are pleased to offer you an appointment as a Graduate Research Assistant (GRA) at
            Universiti Teknologi PETRONAS, under the following project:</p>

        <table class="info">
            <tr><td class="label">Project Title</td><td>{{ $detail->project_title }}</td></tr>
            <tr><td class="label">Project Leader</td><td>{{ $detail->project_leader_name }}</td></tr>
            <tr><td class="label">Programme</td><td>{{ $profile->programme }} ({{ strtoupper($profile->level_of_study) }})</td></tr>
            <tr><td class="label">Service Period</td><td>{{ $detail->service_start_date->format('j F Y') }} to {{ $detail->service_end_date->format('j F Y') }}</td></tr>
            <tr><td class="label">Monthly Allowance</td><td>RM {{ number_format($detail->proposed_allowance, 2) }} (subject to grant affordability)</td></tr>
        </table>

        <p>This appointment is governed by the GRA Scheme Terms and Conditions in effect at the time of your service, and is contingent on your continued fulfilment of the applicable Stage Gate requirements for your level of study.</p>
        <p>This letter is generated through UResearch 2.0 and does not require a physical signature.</p>
    </div>

    <div class="signature">
        <p>Issued by:</p>
        <div class="line"></div>
        <p>Centre for Graduate Studies (CGS)<br>Universiti Teknologi PETRONAS</p>
    </div>
</body>
</html>
