@php
    /**
     * Appeal for Extension of Hardbound Thesis Submission, reproduced from
     * CGS's memo template.
     *
     * This is the BLANK the candidate downloads. The template's red starred
     * placeholders -- *HOD/Chair, *Supervisor, *Student Name, *Department,
     * *Student ID -- are filled in from the portal's own records, and
     * everything the candidate has to say in their own words is left empty
     * for them: the body of the letter and their signature.
     *
     * They complete it, sign it and upload it back, the same way the
     * Hardbound Thesis Submission form (UTP/CGS/021) works. Nothing the
     * candidate writes passes through here, so nothing they write can be
     * reworded by the portal.
     *
     * The endorsements are NOT on this page. Once the candidate's own file
     * is what travels, the portal cannot write inside it, so the Supervisor
     * and HOD/Chair endorsements are stamped onto the endorsement slip that
     * accompanies it -- see endorsement.blade.php.
     */
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Appeal for Extension of Hardbound Thesis Submission</title>
    <style>
        /* One page, like the paper memo. Everything below is sized so that a
           memo with both endorsements and a reason of a few paragraphs still
           lands on a single sheet. */
        @page { margin: 14mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #000; line-height: 1.45; }

        .logo { text-align: center; margin-bottom: 2px; }
        .logo img { height: 58px; }
        h1 { font-size: 14px; font-weight: bold; text-align: center; margin: 0 0 10px; letter-spacing: 0.4px; }

        table.addr { width: 100%; border-collapse: collapse; }
        table.addr td { padding: 3.5px 0; vertical-align: top; border-bottom: 1.2px solid #000; }
        table.addr tr:first-child td { border-top: 1.2px solid #000; }
        table.addr td.k { width: 78px; font-weight: bold; }
        table.addr td.c { width: 14px; }

        h2 { font-size: 11px; font-weight: bold; text-decoration: underline; margin: 14px 0 10px; }

        .body p { margin: 0 0 8px; text-align: justify; }
        .closing { margin-top: 20px; }

        .sigline { position: relative; height: 34px; }
        .sigline img { position: absolute; left: 0; bottom: 1px; max-height: 32px; max-width: 170px; }
        .rule { border-bottom: 1px dotted #000; width: 200px; }
        .who { margin-top: 2px; }

        /* The two endorsements sit side by side rather than stacked: the
           page has the width, and stacking them pushed the Dean's block --
           the one that has to be signed -- onto a second sheet. */
        /* Ruled writing space in the body of a blank memo. */
        .write { border-bottom: 1px solid #999; height: 17px; margin-bottom: 4px; }

        .verdict { margin-top: 26px; }
        .foot { position: fixed; bottom: 0; left: 0; right: 0;
                border-top: 1.2px solid #000; padding-top: 3px;
                text-align: center; font-size: 8.5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS"></div>
    <h1>MEMORANDUM</h1>

    <table class="addr">
        <tr>
            <td class="k">To</td><td class="c">:</td>
            <td>
                {{ $dean?->name ?? 'Dean, Postgraduate and Research' }}<br>
                Dean, Postgraduate and Research<br>
                Universiti Teknologi PETRONAS
            </td>
        </tr>
        <tr>
            <td class="k">Through</td><td class="c">:</td>
            <td>{{ $chairName ?: 'HOD/Chair' }}</td>
        </tr>
        <tr>
            <td class="k">Through</td><td class="c">:</td>
            <td>{{ $supervisorName ?: 'Supervisor' }}</td>
        </tr>
        <tr>
            <td class="k">From</td><td class="c">:</td>
            <td>
                {{ $student?->name }}<br>
                {{ $student?->department }}
            </td>
        </tr>
        <tr>
            <td class="k">Date</td><td class="c">:</td>
            <td></td>
        </tr>
    </table>

    <h2>Appeal for Extension of Hardbound Thesis Submission</h2>

    <div class="body">
        <p>Dear {{ $dean?->name ?? 'Professor' }},</p>

        {{-- Left for the candidate. The ruled lines are what a blank memo
             gives someone filling it in by hand or on screen. --}}
        @for ($line = 0; $line < 14; $line++)
            <div class="write"></div>
        @endfor
    </div>

    <div class="closing">
        <p style="margin: 0 0 4px;">Yours sincerely,</p>

        <div class="sigline"></div>
        <div class="rule"></div>
        <div class="who">
            {{ $student?->name }}<br>
            {{ $student?->matric_no }}<br>
            {{ $student?->department }}
        </div>
    </div>

    <div class="verdict">
        <p style="margin: 0 0 22px;">Approved / Not Approved</p>

        <div class="rule"></div>
        <div class="who">
            {{ $dean?->name ?? '' }}<br>
            Dean<br>
            Postgraduate and Research
        </div>
    </div>

    <div class="foot">Universiti Teknologi PETRONAS</div>
</body>
</html>
