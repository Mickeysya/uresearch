@php
    /**
     * The endorsement slip that travels with the candidate's uploaded memo.
     *
     * The memo itself is now the candidate's own file -- they fill it in and
     * sign it -- and the portal cannot write inside an uploaded PDF without
     * a PDF-editing library this project does not carry. So the routing the
     * paper memo does in its "Through" lines is recorded here instead: who
     * endorsed it, when, and their signature, stamped the same way the
     * Confirmation of Correction is.
     *
     * $signatures is keyed by stage key ('supervisor', 'chair'); a stage
     * that has not endorsed yet has no entry and prints an empty rule. The
     * Dean's block is always left blank -- the Dean signs off-portal, and
     * CGS emails the candidate the outcome.
     */
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Endorsement of Appeal for Extension: #{{ $application->id }}</title>
    <style>
        @page { margin: 16mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #000; line-height: 1.45; }

        .logo { text-align: center; margin-bottom: 2px; }
        .logo img { height: 58px; }
        h1 { font-size: 14px; font-weight: bold; text-align: center; margin: 0 0 4px; letter-spacing: 0.4px; }
        .sub { text-align: center; font-size: 10px; margin: 0 0 16px; }

        table.addr { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.addr td { padding: 3.5px 0; vertical-align: top; border-bottom: 1.2px solid #000; }
        table.addr tr:first-child td { border-top: 1.2px solid #000; }
        table.addr td.k { width: 120px; font-weight: bold; }
        table.addr td.c { width: 14px; }

        h2 { font-size: 11px; font-weight: bold; text-decoration: underline; margin: 0 0 10px; }
        p { margin: 0 0 10px; text-align: justify; }

        table.endorse { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.endorse td { width: 50%; vertical-align: top; padding-right: 16px; }
        .sigline { position: relative; height: 36px; }
        .sigline img { position: absolute; left: 0; bottom: 1px; max-height: 34px; max-width: 170px; }
        .rule { border-bottom: 1px dotted #000; width: 200px; }
        .who { margin-top: 2px; }
        .pending { color: #666; font-style: italic; }

        .verdict { margin-top: 30px; }
        .foot { position: fixed; bottom: 0; left: 0; right: 0;
                border-top: 1.2px solid #000; padding-top: 3px;
                text-align: center; font-size: 8.5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="logo"><img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS"></div>
    <h1>ENDORSEMENT OF APPEAL FOR EXTENSION</h1>
    <p class="sub">Hardbound Thesis Submission &middot; Appeal #{{ $application->id }}</p>

    <table class="addr">
        <tr>
            <td class="k">Candidate</td><td class="c">:</td>
            <td>{{ $student?->name }} @if ($student?->matric_no) ({{ $student->matric_no }}) @endif</td>
        </tr>
        <tr>
            <td class="k">Department</td><td class="c">:</td>
            <td>{{ $student?->department }}</td>
        </tr>
        <tr>
            <td class="k">Appeal filed</td><td class="c">:</td>
            <td>{{ $application->created_at?->format('j F Y') }}</td>
        </tr>
        <tr>
            <td class="k">Extension until</td><td class="c">:</td>
            <td>
                <b>{{ $detail->requested_until?->format('j F Y') }}</b>
                @if ($detail->original_deadline)
                    &nbsp;(from {{ $detail->original_deadline->format('j F Y') }})
                @endif
            </td>
        </tr>
    </table>

    <h2>Endorsement</h2>

    <p>
        The candidate's memo, <i>Appeal for Extension of Hardbound Thesis Submission</i>, is
        attached as submitted by the candidate. The signatories below have endorsed it and it
        is forwarded to the Dean of Postgraduate and Research for a decision.
    </p>

    <table class="endorse">
        <tr>
            @foreach (['supervisor' => 'Supervisor', 'chair' => 'HOD/Chair'] as $key => $role)
                <td>
                    <div class="sigline">
                        @if (isset($signatures[$key]['image']))
                            <img src="{{ $signatures[$key]['image'] }}" alt="Signature of {{ $signatures[$key]['name'] }}">
                        @endif
                    </div>
                    <div class="rule"></div>
                    <div class="who">
                        @isset ($signatures[$key])
                            {{ $signatures[$key]['name'] }}<br>
                            {{ $role }}<br>
                            {{ $signatures[$key]['date'] }}
                        @else
                            <span class="pending">{{ $role }}: not yet endorsed</span>
                        @endisset
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

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
