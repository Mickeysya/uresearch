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

    <p>Ref: UResearch/GA-OFFER/{{ $application->id }}</p>
    <p>Date: {{ now()->format('j F Y') }}</p>

    <div class="title">GRADUATE ASSISTANT (GA) — OFFER LETTER</div>

    <div class="body">
        <p>Dear <b>{{ $student->name }}</b>,</p>
        <p>We are pleased to offer you an appointment as a Graduate Assistant (GA) at
            Universiti Teknologi PETRONAS, following successful completion of your interview.</p>

        <table class="info">
            <tr><td class="label">Department / Unit</td><td>{{ $detail->department_unit ?? '—' }}</td></tr>
            <tr><td class="label">Programme</td><td>{{ $profile->programme }} ({{ strtoupper($profile->level_of_study) }})</td></tr>
            <tr><td class="label">Service Period</td><td>{{ $detail->service_start_date->format('j F Y') }} to {{ $detail->service_end_date->format('j F Y') }}</td></tr>
            <tr><td class="label">Monthly Allowance</td><td>RM {{ number_format($detail->proposed_allowance, 2) }}</td></tr>
        </table>

        <p>This letter is generated through UResearch 2.0 and does not require a physical signature.</p>
    </div>

    <div class="signature">
        <p>Issued by:</p>
        <div class="line"></div>
        <p>Centre for Graduate Studies (CGS)<br>Universiti Teknologi PETRONAS</p>
    </div>
</body>
</html>
