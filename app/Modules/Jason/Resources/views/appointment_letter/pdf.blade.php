@php
    $internal = $detail->isInternal();
    $role = $internal ? 'Internal' : 'External';
    $deanName = $dean?->name ?? 'Dean, Postgraduate and Research';

    // The internal letter's terms differ from the external one in (c) and (d):
    // no novelty wording, and viva attendance is not conditional.
    $terms = [
        'To evaluate the thesis of the student independently.',
        'To complete and submit a comprehensive report prior to the viva voce examination.',
        $internal
            ? 'To provide an indication of the original contribution made by the candidate.'
            : 'To provide an indication of the original contribution or novelty made by the candidate.',
        $internal
            ? 'To attend and evaluate the viva voce examination.'
            : 'To attend and evaluate the viva voce examination together with other appointed examiners if required.',
        'The examiners are given a maximum of six (6) weeks upon acknowledgment of the receipt of the thesis, to complete the assessment of the draft thesis and send the report to the department.',
        'The examiner can be assigned one (1) assignment at one (1) time. The examiner can be appointed to other assignments upon completion of the 1st assignment during his/her tenure.',
    ];

    $conflicts = [
        'Working relationship' => [
            "Co-authored paper with the student and/or student's supervisor in the research thesis to be examined",
            'Worked with the student on matters of analysis and/or synthesis in the research thesis to be examined',
            'Has provided funds to the student in the research thesis to be examined',
            'Has refereed/edited a paper published by the student in the research thesis to be examined',
            'Has been employed or currently employed by the student',
        ],
        'Personal relationship' => [
            "A close relative of the student/student's supervisor (e.g. spouse, child or parent, sibling and in-laws)",
            'Has a personal relationship of enmity with the student or supervisor',
            'A Mentor/Associate of the student',
        ],
        'Others' => $internal ? [
            'Any other potential conflict of interest. State here:',
        ] : [
            'Has currently been appointed by Universiti Teknologi PETRONAS (e.g. Adjunct lecturer, Adjunct Professor, IAP, Visiting Professor etc)',
            'A former (leaving UTP) staff/student of Universiti Teknologi PETRONAS for less than five years',
            'Any other potential conflict of interest. State here:',
        ],
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Appointment as {{ $role }} Examiner — Application #{{ $application->id }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.5; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .letterhead { text-align: center; margin-bottom: 20px; }
        .letterhead h1 { font-size: 14px; margin: 0 0 2px; color: #002147; letter-spacing: 0.5px; }
        .letterhead p { margin: 1px 0; font-size: 9px; color: #444; }
        h2.doc-title { font-size: 11px; text-transform: uppercase; border: 1px solid #1a1a1a; padding: 5px; text-align: center; margin: 0 0 18px; }
        .subject { font-weight: bold; text-transform: uppercase; margin: 16px 0; }
        table.details { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        table.details td { padding: 2px 4px; vertical-align: top; }
        table.details td.label { width: 150px; }
        table.details td.sep { width: 10px; }
        table.grid { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.grid th, table.grid td { border: 1px solid #1a1a1a; padding: 5px 6px; text-align: left; vertical-align: top; }
        table.grid th { background: #eef1f6; font-size: 10px; }
        ol.terms { margin: 6px 0 6px 14px; padding-left: 10px; }
        ol.terms li { margin-bottom: 5px; }
        .signature-block { margin-top: 46px; }
        .signature-line { margin-top: 44px; border-top: 1px solid #1a1a1a; width: 280px; }
        .fill-line { display: inline-block; border-bottom: 1px solid #1a1a1a; min-width: 180px; }
        .footer-note { margin-top: 28px; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>

{{-- ---------------------------------------------------------------- 1. The letter --}}
<div class="page">
    <div class="letterhead">
        <h1>UNIVERSITI TEKNOLOGI PETRONAS</h1>
        <p>Centre for Graduate Studies &middot; Postgraduate and Research</p>
        <p>32610 Seri Iskandar, Perak Darul Ridzuan, Malaysia</p>
    </div>

    <p>
        Our Ref: {{ $detail->letter_ref_no }}<br><br>
        {{ $issuedAt->format('jS F Y') }}
    </p>

    <p>
        {{ $detail->examiner_name }}<br>
        {{ $detail->examiner_institution }}
    </p>

    <p>Dear {{ $detail->examiner_name }},</p>

    <p class="subject">Appointment as Thesis {{ $role }} Examiner</p>

    <p>
        Universiti Teknologi PETRONAS is pleased to appoint you as a thesis
        {{ $role }} Examiner. The candidate's details are as follows:
    </p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
        <tr><td class="label">Programme</td><td class="sep">:</td><td>{{ $detail->candidate_programme }}</td></tr>
        <tr><td class="label">Supervisor name</td><td class="sep">:</td><td>{{ $detail->supervisor_name }}</td></tr>
        <tr><td class="label">Title of the Thesis</td><td class="sep">:</td><td><b>{{ $detail->thesis_title }}</b></td></tr>
    </table>

    <p>1.&nbsp;&nbsp;The terms of services of your appointment are as follows:</p>
    <ol class="terms" type="a">
        @foreach ($terms as $term)
            <li>{{ $term }}</li>
        @endforeach
    </ol>

    @unless ($internal)
        <p>
            2.&nbsp;&nbsp;As part of your contribution, you will be provided with benefits and
            entitlement (refer to Attachment 1) for examining the thesis.
        </p>
    @endunless

    <p>
        {{ $internal ? '2' : '3' }}.&nbsp;&nbsp;We sincerely hope that you will accept this appointment and
        return the attached acknowledgement slip to our office at the address above. Upon
        acceptance, a copy of the thesis will be forwarded to you for evaluation.
    </p>

    <p>Thank you.</p>

    <div class="signature-block">
        <p>Best regards,</p>
        <div class="signature-line"></div>
        <p>
            <b>{{ $deanName }}</b><br>
            Dean<br>
            Postgraduate and Research
        </p>
    </div>

    <div class="footer-note">
        Generated by UResearch 2.0 on {{ $issuedAt->format('j F Y, g:ia') }} and archived
        against Application #{{ $application->id }}.
    </div>
</div>

{{-- ------------------------------------------- 2. Attachment 1 (external examiners only) --}}
@unless ($internal)
    <div class="page">
        <h2 class="doc-title">Attachment 1 — Benefits and Entitlement</h2>

        <table class="grid">
            <tr>
                <th style="width: 170px;">Honorarium</th>
                <td>
                    Local universities RM 800 for each PhD thesis examined.<br>
                    Oversea universities RM 1200 for each PhD thesis examined.
                </td>
            </tr>
            <tr>
                <th>Overland transport</th>
                <td>
                    Examiner is required to provide:<br>
                    &bull; Google map for mileage claims, which is RM0.80 per KM.<br>
                    &bull; Toll receipt (if any)<br>
                    &bull; Parking receipt (if any)
                </td>
            </tr>
            <tr>
                <th>Taxi fare</th>
                <td>Examiner may claim taxi fare to and from the airport or workplace/home to the University.</td>
            </tr>
            <tr>
                <th>Air travel</th>
                <td>Economy class from the airport nearest to the home address.</td>
            </tr>
            <tr>
                <th>Lodging allowance</th>
                <td>An allowance of RM50 per night is payable if the examiner opts to arrange their own lodging.</td>
            </tr>
            <tr>
                <th>Subsistence allowance</th>
                <td>RM 100.00 (Ringgit One Hundred only) per day.</td>
            </tr>
            <tr>
                <th>Accommodation</th>
                <td>
                    The University will arrange lodging as deemed suitable and bear the cost of the lodging.<br><br>
                    Personal expenses such as telephone charges, laundry, mini bar, etc. shall be borne by the examiner.
                </td>
            </tr>
        </table>

        <p style="font-size: 9.5px;">
            Note: UTP shall have the discretion to revise the TOR, honorarium and allowances
            package as and when required, and shall seek prior approval from the Approving
            Authority before making any changes.
        </p>
        <p style="font-size: 9px; font-style: italic;">
            Reference: Guideline for External Member Appointments Related to Academic Division<br>
            Ref No.: UTP-GUI-ACA-CAdEx-002
        </p>
    </div>
@endunless

{{-- ------------------------------------------------------ 3. Acknowledgement slip --}}
<div class="page">
    <h2 class="doc-title">Acknowledgement Slip</h2>

    <p style="text-align: right;">Date: <span class="fill-line"></span></p>

    <p>
        <b>{{ $deanName }}</b><br>
        Dean<br>
        Postgraduate and Research<br>
        Universiti Teknologi PETRONAS<br>
        32610 Bandar Seri Iskandar<br>
        Perak Darul Ridzuan
    </p>

    <p>Dear Sir/Madam,</p>

    <p class="subject">Appointment as {{ $role }} Examiner</p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
    </table>

    <p>
        I, {{ $detail->examiner_name }}, I.C./Passport Number <span class="fill-line"></span>
        hereby <b>accept / reject *</b> your nomination for the appointment as
        {{ $internal ? 'an Internal' : 'an External' }} Examiner for the above candidate,
        according to the terms of reference stated in your letter
        {{ $detail->letter_ref_no }} dated {{ $issuedAt->format('jS F Y') }}.
    </p>

    <p>
        I hereby confirm that I have read and completed the declaration on conflict of
        interest, to the best of my knowledge, and submitted it to your office for
        reference and further process.
    </p>

    <p>Thank you.</p>
    <p>Yours sincerely,</p>

    <div class="signature-line"></div>
    <table class="details">
        <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $detail->examiner_name }}</td></tr>
        <tr><td class="label">Institution</td><td class="sep">:</td><td>{{ $detail->examiner_institution }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
        <tr><td class="label">Tel No</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
        <tr><td class="label">E-mail</td><td class="sep">:</td><td>{{ $detail->examiner_email }}</td></tr>
        @unless ($internal)
            <tr><td class="label">Bank Name</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
            <tr><td class="label">Account No</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
        @endunless
    </table>

    @unless ($internal)
        <p style="font-size: 9.5px;">
            (Kindly attach proof of account number — a copy of the passbook/bank statement —
            and Identity Card/Passport.)
        </p>
    @endunless

    <p style="font-size: 9.5px;">* Please delete as appropriate.</p>
</div>

{{-- ------------------------------------------- 4. Conflict of interest declaration --}}
<div class="page">
    <h2 class="doc-title">Conflict of Interest Declaration</h2>

    <table class="grid">
        <thead>
            <tr>
                <th style="width: 110px;">Category</th>
                <th>Type of conflict of interest</th>
                <th style="width: 40px; text-align: center;">Yes</th>
                <th style="width: 40px; text-align: center;">No</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($conflicts as $category => $items)
                @foreach ($items as $i => $item)
                    <tr>
                        @if ($i === 0)
                            <td rowspan="{{ count($items) }}">{{ $category }}</td>
                        @endif
                        <td>{{ $item }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <p>Thank you.</p>
    <p>Yours sincerely,</p>

    <div class="signature-line"></div>
    <table class="details">
        <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $detail->examiner_name }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
    </table>
</div>

{{-- --------------------------------------------- 5. Thesis receipt confirmation --}}
<div class="page">
    <h2 class="doc-title">Thesis Receipt Confirmation</h2>

    <p style="text-align: right;">Date: <span class="fill-line"></span></p>

    <p>
        <b>{{ $deanName }}</b><br>
        Dean<br>
        Postgraduate and Research<br>
        Universiti Teknologi PETRONAS<br>
        32610 Bandar Seri Iskandar<br>
        Perak Darul Ridzuan
    </p>

    <p>Dear Sir/Madam,</p>

    <p class="subject">Thesis Receipt Confirmation</p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
    </table>

    <p>
        I, <b>{{ $detail->examiner_name }}</b>, hereby confirm that I have received a copy
        of the candidate's thesis in good condition.
    </p>

    <p>Note (if any): <span class="fill-line" style="min-width: 380px;"></span></p>

    <p>Thank you.</p>
    <p>Yours sincerely,</p>

    <div class="signature-line"></div>
    <table class="details">
        <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $detail->examiner_name }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td><span class="fill-line"></span></td></tr>
    </table>
</div>

</body>
</html>
