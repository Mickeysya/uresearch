{{--
    "Correction of Master's / Doctoral Thesis": one letter per current main
    examiner, rendered by Hani\Support\ReVivaLetters with Dompdf.

    Layout and wording follow CGS's real letter. To restyle, edit
    the <style> block only: every element below uses one of its classes.
    Signatory, address and return contacts come from hani.reviva.letter.

    Data: $reference, $date, $examiner, $examinerPlace, $student, $degreeWord,
    $degreeName, $programme, $thesisTitle, $workingDays, $dueDate,
    $letter (config), $round
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Correction of {{ $degreeWord }} Thesis</title>
<style>
    @page { margin: 16mm 24mm 16mm 24mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.24; color: #000; }
    .logo { text-align: center; margin-bottom: 6pt; }
    .logo img { height: 22mm; }
    p { margin: 0 0 8pt 0; }
    .addressee { margin: 10pt 0 12pt 0; }
    .subject { font-weight: bold; text-transform: uppercase; }
    .details { margin: 0 0 12pt 8pt; border-collapse: collapse; font-weight: bold; }
    .details td { padding: 0 0 2pt 0; vertical-align: top; }
    .details .label { width: 40mm; font-weight: normal; }
    .details .colon { width: 7mm; font-weight: normal; }
    .numbered { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
    .numbered td { padding: 0; vertical-align: top; }
    .numbered .n { width: 12mm; }
    .contact { margin: 8pt 0 0 0; }
    .contact b { display: block; }
    .email { font-style: italic; }
    .signature { height: 28pt; }
    .footer { position: fixed; bottom: 0; left: 0; font-size: 7.5pt; }
</style>
</head>
<body>
    <div class="logo"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/UTP_logo.png'))) }}" alt="Universiti Teknologi PETRONAS"></div>

    <p>Our ref: {{ $reference }}</p>
    <p>{{ $date->format('j') }}<sup>{{ $date->format('S') }}</sup> {{ $date->format('F Y') }}</p>

    <div class="addressee">
        {{ $examiner->name }}<br>
        @foreach ($examinerPlace as $line)
            {{ $line }}<br>
        @endforeach
    </div>

    <p>Dear {{ $examiner->name }},</p>

    <p class="subject">Correction of {{ $degreeWord }} Thesis</p>

    <p>{{ $letter['institution'] }} is pleased to submit the revised thesis of the below mentioned postgraduate student for your further action. The candidate's details are as follows:</p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="colon">:</td><td>{{ $student->name }}</td></tr>
        <tr><td class="label">Degree</td><td class="colon">:</td><td>{{ $degreeName }}</td></tr>
        <tr><td class="label">Programme</td><td class="colon">:</td><td>{{ $programme }}</td></tr>
        <tr><td class="label">Title of the Thesis</td><td class="colon">:</td><td>{{ $thesisTitle }}</td></tr>
        @if ($round > 1)
            <tr><td class="label">Re-viva Round</td><td class="colon">:</td><td>{{ $round }}</td></tr>
        @endif
    </table>

    <table class="numbered">
        <tr><td class="n">2.</td><td>Upon receiving the thesis, the examiners are given a maximum of {{ $workingDaysWords }} ({{ $workingDays }}) working days to examine the thesis, that is by {{ $dueDate->format('j F Y') }}.</td></tr>
    </table>

    <table class="numbered">
        <tr><td class="n">3.</td><td>
            We sincerely hope that you will return the Thesis Re-evaluation Report (UTP/CGS/024) to our office at the address or email to the below personnels.
            @php
                $first = $letter['return_contacts'][0] ?? null;
            @endphp
            @if ($first)
                <div class="contact">
                    <b>{{ $first['name'] }}</b>
                    Centre for Graduate Studies<br>
                    {{ $letter['institution'] }}<br>
                    @foreach ($letter['address'] as $line)
                        {{ $line }}<br>
                    @endforeach
                    @if (! empty($first['phone'])) Tel: {{ $first['phone'] }}<br> @endif
                    Email: <span class="email">{{ collect($letter['return_contacts'])->pluck('email')->implode(' or ') }}</span>
                </div>
            @endif
        </td></tr>
    </table>

    <p>Thank you.</p>
    <p>Best regards,</p>
    <div class="signature"></div>
    <p><b>{{ $letter['signatory_name'] }}</b><br>
        @foreach (explode(', ', $letter['signatory_title'], 2) as $i => $part)
            {{ $part }}{{ $i === 0 && str_contains($letter['signatory_title'], ', ') ? ',' : '' }}<br>
        @endforeach
    </p>

    <div class="footer">Open</div>
</body>
</html>
