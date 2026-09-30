@php
    /**
     * Reproduces the CGS appointment pack as issued: the letter, the benefits
     * attachment (external only), the acknowledgement slip, the conflict of
     * interest declaration and the thesis receipt confirmation.
     *
     * The internal and external templates differ in more than a word -- the
     * reference series, the salutation, the clause numbering ("terms of
     * services" vs "terms of reference"), the entitlements, the bank details
     * on the slip and the conflict rows -- so each difference is marked
     * against $internal rather than kept as two near-identical files.
     */
    $internal = $examiner->isInternal();
    $role = $internal ? 'Internal' : 'External';
    $deanName = $dean?->name ?? 'Dean, Postgraduate and Research';

    // The CGS office contacts printed on the template.
    $contacts = ['hafiz.arif@utp.edu.my', 'wahedarahayu_rashid@utp.edu.my'];

    // Letters prepared before the address was captured fall back to the single
    // institution line the nomination has always carried.
    $address = trim((string) $examiner->examiner_address) ?: (string) $examiner->examiner_institution;
    $addressLines = array_filter(preg_split('/\r\n|\r|\n/', $address) ?: []);

    $terms = [
        'To evaluate the thesis of the student independently.',
        'To complete and submit a comprehensive report prior to the viva voce examination.',
        $internal
            ? 'To provide an indication of the original contribution made by the candidate.'
            : 'To provide an indication of the original contribution or novelty made by the candidate.',
        $internal
            ? 'To attend and evaluate the viva voce examination.'
            : 'To attend and evaluate the viva voce examination together with other appointed examiners if required.',
        'The examiners are given a maximum of six (6) weeks upon acknowledgment of the receipt of the thesis, to complete the assessment of the draft PhD thesis and send the report to the department.',
        'The examiner can be assigned one (1) assignment at one (1) time. The examiner can be appointed to other assignment upon completion of 1st assignment during his/her tenure.',
    ];

    /**
     * The conflict of interest declaration.
     *
     * CGS revised it for external examiners (the MSc and PhD templates dated
     * September 2026): a purpose preamble, a "Proposal" column in place of
     * "Type of conflict of interest", six working-relationship criteria in
     * place of five, wider personal and other criteria, and a signed
     * declaration on a page of its own. The internal template has not been
     * reissued, so it keeps the older, shorter wording -- swap it here when
     * the internal version arrives.
     */
    $conflicts = $internal ? [
        'Working relationship' => [
            "Co-authored paper with the student and/or student's supervisor in the research thesis to be examined",
            'Worked with the student on matters of analysis and/or synthesis in the research thesis to be examined',
            'Funds have been provided to the student in the research thesis to be examined',
            'Has refereed/edited a paper published by the student in the research thesis to be examined',
            'Has been employed or currently employed by the student',
        ],
        'Personal relationship' => [
            "A close relative of the student/student's supervisor (e.g. spouse, child or parent, sibling and in-laws)",
            'Has a personal relationship of enmity with the student or supervisor',
            'A Mentor/Associate of the student',
        ],
        'Others' => [
            'Any other potential conflict of interest<br>State here:',
        ],
    ] : [
        'Working relationship' => [
            'Has co-authored any scholarly publication (e.g. journal article, conference paper, book chapter, '
                .'book, patent, technical report or other research output) with the student and/or the '
                ."student's supervisor(s) in the same or a closely related field of research, including the "
                ."student's thesis research area, within the last five (5) years.",
            "Have collaborated with the student and/or the student's supervisor(s) on the design, methodology, "
                .'data collection, analysis, interpretation, synthesis or writing of the research thesis or any '
                .'research directly related to the thesis under examination.',
            'Have provided, administered or received research funding, sponsorship or other financial support '
                ."directly related to the student's research thesis or associated research project.",
            'Has reviewed, edited, proofread, or provided substantial intellectual input to the research thesis '
                ."under examination or to any publication or other scholarly output arising from the student's "
                .'research thesis.',
            'Has been employed by or is currently employed by the student or by an organisation in which the '
                .'student has a direct supervisory, managerial or reporting relationship.',
            'Has supervised, co-supervised, served on a supervisory committee or provided formal academic advice '
                .'to the student in relation to the thesis or research project under examination.',
        ],
        'Personal relationship' => [
            "Is a close relative of the student and/or the student's supervisor(s) (e.g. spouse, parent, child, "
                .'sibling, grandparent, grandchild or in-law).',
            'Has a close personal relationship or a relationship of enmity with the student and/or the '
                ."student's supervisor(s) that may affect or reasonably be perceived to affect, impartiality.",
            'Has served as a mentor, advisor, collaborator, associate or other close professional associate of '
                .'the student that may give rise to an actual, potential or perceived conflict of interest.',
        ],
        'Others' => [
            'Currently holds an appointment with Universiti Teknologi PETRONAS (UTP) (e.g. Adjunct Lecturer, '
                .'Adjunct Professor, Industry Advisory Panel (IAP) member, Visiting Professor or any other '
                .'honorary or affiliated appointment).',
            'Is a former staff member or student of Universiti Teknologi PETRONAS (UTP) who left or graduated '
                .'less than five (5) years ago.',
            'Has any other actual, potential or perceived conflict of interest that may compromise or reasonably '
                .'be perceived to compromise, the independence, objectivity or impartiality of the examination.'
                .'<br><br>If yes, please provide details: <span class="fill" style="min-width: 210px;"></span>',
        ],
    ];

    // The revised external forms carry the 026 series; the internal template
    // has not been reissued and stays on 025.
    $formCode = $internal ? 'UTP/CGS/025' : 'UTP/CGS/026';

@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Appointment as {{ $role }} Examiner: Application #{{ $application->id }}</title>
    <style>
        @page { margin: 18mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #000; line-height: 1.45; }
        p { margin: 0 0 10px; }

        /* Each sheet is a fixed box so the UTP footer sits on the baseline of
           the letter pages exactly as it does on the issued document. */
        .sheet { position: relative; height: 247mm; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }

        .logo { text-align: center; margin-bottom: 16px; }
        .logo img { height: 62px; }

        .form-code { text-align: right; font-size: 10px; margin-bottom: 4px; }
        h2.doc-title { font-size: 10.5px; font-weight: bold; text-transform: uppercase;
                       border: 1px solid #000; padding: 5px; text-align: center; margin: 0 0 20px; }
        .subject { font-weight: bold; margin: 14px 0; }
        .subject.underline { text-decoration: underline; }

        table.details { border-collapse: collapse; margin: 10px 0 14px 14px; }
        table.details td { padding: 1px 3px; vertical-align: top; }
        table.details td.label { width: 132px; }
        table.details td.sep { width: 8px; }

        table.grid { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 5px 6px; text-align: left; vertical-align: top; }
        table.grid th { font-weight: bold; }

        /* The revised external declaration has twelve criteria in full legal
           wording. At the body size it runs onto a second sheet, which the
           issued form does not: it is one page, table and all. */
        table.grid.coi { font-size: 9px; line-height: 1.3; margin: 8px 0 0; }
        table.grid.coi th, table.grid.coi td { padding: 3px 5px; }

        ol.terms { margin: 6px 0 10px 20px; padding-left: 12px; }
        ol.terms li { margin-bottom: 6px; }

        .clause { margin: 0 0 10px; padding-left: 22px; text-indent: -22px; }
        .sig-line { margin-top: 40px; border-top: 1px solid #000; width: 300px; }
        .fill { display: inline-block; border-bottom: 1px solid #000; min-width: 170px; }

        .utp-footer { position: absolute; bottom: 0; left: 0; right: 0; text-align: center; }
        .utp-footer .name { font-size: 11px; color: #1F3D7A; letter-spacing: 0.4px; }
        .utp-footer .sub { font-size: 7.5px; font-style: italic; color: #1F3D7A; }
        .utp-footer .addr { font-size: 7.5px; color: #333; margin-top: 5px; }
    </style>
</head>
<body>

@php
    // Repeated verbatim at the foot of each letter page on the issued document.
    $footer = '<div class="utp-footer">'
        .'<div class="name">UNIVERSITI TEKNOLOGI PETRONAS</div>'
        .'<div class="sub">INSTITUTE OF TECHNOLOGY PETRONAS SDN. BHD.</div>'
        .'<div class="sub">(Company No : 352875U) Wholly owned subsidiary of PETRONAS</div>'
        .'<div class="addr">32610 Seri Iskandar, Perak Darul Ridzuan, Malaysia.<br>'
        .'Tel : 1 300 22 8887&nbsp;&nbsp;Fax : 605-365 4075&nbsp;&nbsp;Website : www.utp.edu.my</div>'
        .'</div>';

    $deanBlock = '<p><b>'.e($deanName).'</b><br>Dean<br>Postgraduate and Research<br>'
        .'Universiti Teknologi PETRONAS<br>32610 Bandar Seri Iskandar<br>Perak Darul Ridzuan</p>'
        .'<p>(Tel: 05-3687474)</p>';
@endphp

{{-- ============================================================ letter, page 1 --}}
<div class="sheet">
    <div class="logo">
        <img src="{{ public_path('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS">
    </div>

    <p>
        Our {{ $internal ? 'ref' : 'Ref' }}: {{ $examiner->letter_ref_no }}
    </p>

    <p>{{ $issuedAt->format('jS F Y') }}</p>

    <p>
        {{ $examiner->examiner_name }}<br>
        @foreach ($addressLines as $line)
            {{ $line }}<br>
        @endforeach
    </p>

    <p>Dear {{ $internal ? 'Sir/Madam' : $examiner->examiner_name }},</p>

    <p class="subject">APPOINTMENT AS {{ $internal ? 'INTERNAL EXAMINER' : 'THESIS EXTERNAL EXAMINER' }}</p>

    <p>
        Universiti Teknologi PETRONAS is pleased to appoint you as a thesis
        {{ $role }} Examiner. The
        {{ $internal ? 'candidates details are' : "candidate's details are" }} as follows:
    </p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
        <tr><td class="label">Programme</td><td class="sep">:</td><td>{{ $detail->candidate_programme }}</td></tr>
        <tr><td class="label">Supervisor name</td><td class="sep">:</td><td>{{ $detail->supervisor_name }}</td></tr>
        <tr>
            <td class="label">Title of the Thesis</td><td class="sep">:</td>
            <td><b>{{ $detail->thesis_title }}</b></td>
        </tr>
    </table>

    <p class="clause">
        {{ $internal ? '2.' : '1.' }}&nbsp;&nbsp;&nbsp;&nbsp;The terms of
        {{ $internal ? 'reference for' : 'services of' }} your appointment are as follows:
    </p>

    <ol class="terms" type="a">
        @foreach ($terms as $term)
            <li>{{ $term }}</li>
        @endforeach
    </ol>

    @unless ($internal)
        <p class="clause">
            2.&nbsp;&nbsp;&nbsp;&nbsp;As part of your contribution, you will be provided with
            benefits and entitlement refer to Attachment 1) for examining the thesis.
        </p>
    @endunless

    {!! $footer !!}
</div>

{{-- ============================================================ letter, page 2 --}}
<div class="sheet">
    <p class="clause">
        3.&nbsp;&nbsp;&nbsp;&nbsp;We sincerely hope that you will accept this appointment and
        return the attached acknowledgement slip (UTP/PPS/020) to our office at the address
        below or email {{ $contacts[0] }} , {{ $contacts[1] }} as soon as possible. Upon
        acceptance, a copy of the thesis will be forwarded to you for evaluation.
    </p>

    @unless ($internal)
        <p class="clause">
            4.&nbsp;&nbsp;&nbsp;&nbsp;For any inquiries please do not hesitate to email
            {{ $contacts[0] }}, {{ $contacts[1] }}.
        </p>
    @endunless

    <p>Thank you.</p>
    <p>Best regards,</p>

    <div class="sig-line"></div>
    <p>
        <b>{{ $deanName }}</b><br>
        Dean<br>
        Postgraduate and Research
    </p>

    {!! $footer !!}
</div>

{{-- =================================================== Attachment 1 (external only) --}}
@unless ($internal)
    <div class="sheet">
        <p style="text-align: center; font-weight: bold;">ATTACHMENT 1</p>
        <p style="text-align: center; font-weight: bold;">BENEFITS AND ENTITLEMENT</p>

        <table class="grid">
            <tr>
                <th style="width: 150px;">Honorarium</th>
                <td>
                    Local universities RM 800 for each PhD thesis examined.<br>
                    Oversea universities RM 1200 each PhD thesis examined.
                </td>
            </tr>
            <tr>
                <th>Overland transport</th>
                <td>
                    Examiner are required to provide:<br>
                    &bull;&nbsp; Google map for mileage claims which is RM0.80 per KM.<br>
                    &bull;&nbsp; Tol receipt (if any)<br>
                    &bull;&nbsp; Parking receipt (if any)
                </td>
            </tr>
            <tr>
                <th>Taxi fare</th>
                <td>Examiner may claim taxi fare to and from airport or workplace/home to the University.</td>
            </tr>
            <tr>
                <th>Air travel</th>
                <td>Economy class from airport nearest to home address.</td>
            </tr>
            <tr>
                <th>Lodging allowance</th>
                <td>An allowance of RM50 per night is payable if the examiner opts to arrange for own lodging.</td>
            </tr>
            <tr>
                <th>Subsistence allowance</th>
                <td>RM 100.00 (Ringgit One Hundred only) per day.</td>
            </tr>
            <tr>
                <th>Accommodation</th>
                <td>
                    The University will arrange for lodging as deemed suitable and bear the cost for the lodging.
                    <br><br>
                    Personal expenses such as telephone charges, laundry, mini bar, etc. shall be borne by the examiner.
                </td>
            </tr>
        </table>

        <p>Note:</p>
        <p>
            UTP shall have the discretion to revise the TOR, honorarium, and allowances package as when
            required and shall seek prior approval from the Approving Authority before making any changes.
        </p>

        <p style="font-size: 9px; font-style: italic; margin-top: 26px;">
            Reference: Guideline for External Member Appointments Related to Academic Division<br>
            Ref No. : UTP-GUI-ACA-CAdEx-002
        </p>
    </div>
@endunless

{{-- ==================================================== acknowledgement slip --}}
<div class="sheet">
    <div class="form-code">{{ $formCode }}</div>
    <h2 class="doc-title">Acknowledgement Slip</h2>

    <p style="text-align: right;">Date: <span class="fill"></span></p>

    {!! $deanBlock !!}

    <p>Dear Sir,</p>

    <p class="subject underline">APPOINTMENT AS {{ strtoupper($role) }} EXAMINER</p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
    </table>

    <p>
        I, {{ $examiner->examiner_name }}, I.C./Passport Number{{ $internal ? ':' : '' }}
        <span class="fill"></span> hereby accept / reject
        {{ $internal ? '*' : ' *' }} your nomination for the appointment as
        {{ $internal ? 'an Internal Examiner' : 'an external examiner for the above candidate' }}
        according to the terms of reference stated in your letter {{ $examiner->letter_ref_no }}
        dated {{ $issuedAt->format('jS F Y') }}.
    </p>

    <p>
        @if ($internal)
            I hereby read and complete the declaration on conflict of interest as specified in
            Appendix {{ $formCode }}, to the best of my knowledge and submit it to your office for
            reference and further process.
        @else
            I hereby had read and completed the declaration on conflict of interest as specified in
            Appendix {{ $formCode }}, to the best of my knowledge and submitted to your office for
            reference and further process.
        @endif
    </p>

    <p>Thank you.</p>
    <p>Yours sincerely,</p>

    <div class="sig-line"></div>

    <table class="details">
        <tr>
            <td class="label">Name</td><td class="sep">:</td>
            <td>
                {{ $examiner->examiner_name }}
                @unless ($internal)
                    @foreach ($addressLines as $line)
                        <br>{{ $line }}
                    @endforeach
                @endunless
            </td>
        </tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td></td></tr>
        @unless ($internal)
            <tr><td class="label">Tel No</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">E-mail</td><td class="sep">:</td><td>{{ $examiner->examiner_email }}</td></tr>
            <tr><td class="label">Bank Name</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">Account No</td><td class="sep">:</td><td></td></tr>
        @endunless
    </table>

    @unless ($internal)
        <p>
            (Kindly attach the proof of account number by a copy of passbook/bank statement &amp;
            Identity Card/Passport)
        </p>
    @endunless
</div>

{{-- =========================================== conflict of interest declaration --}}
<div class="sheet">
    <div class="form-code">{{ $formCode }}</div>
    <h2 class="doc-title">Conflict of Interest Declaration</h2>

    @unless ($internal)
        <p style="font-weight: bold; margin: 0 0 4px;">Purpose of Declaration</p>
        <p style="text-align: justify; margin-bottom: 6px;">
            Universiti Teknologi PETRONAS (&ldquo;UTP&rdquo;) requires all appointed external
            examiners to declare any actual, potential or perceived conflict of interest that may
            affect or reasonably be perceived to affect, their independence, objectivity and
            impartiality in conducting the examination of the candidate's thesis.
        </p>
        <p style="text-align: justify; margin-bottom: 4px;">
            The existence of a disclosed conflict of interest does not automatically disqualify an
            examiner from appointment. UTP reserves the right to assess the nature and materiality
            of any disclosed conflict and determine the appropriate course of action.
        </p>
    @endunless

    <table class="grid @unless ($internal) coi @endunless">
        <thead>
            <tr>
                <th style="width: 92px;">Category</th>
                <th>{{ $internal ? 'Type of conflict of interest' : 'Proposal' }}</th>
                <th style="width: 34px; text-align: center;">Yes</th>
                <th style="width: 34px; text-align: center;">No</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($conflicts as $category => $items)
                @foreach ($items as $i => $item)
                    <tr>
                        @if ($i === 0)
                            <td rowspan="{{ count($items) }}">{{ $category }}</td>
                        @endif
                        <td>{!! $item !!}</td>
                        <td></td>
                        <td></td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    @if ($internal)
        <p style="margin-top: 22px;">Thank you.</p>
        <p>Yours sincerely,</p>

        <div class="sig-line"></div>

        <table class="details">
            <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $examiner->examiner_name }}</td></tr>
            <tr><td class="label">Date</td><td class="sep">:</td><td></td></tr>
        </table>
    @endif
</div>

{{-- The declaration the revised external form puts on a page of its own. --}}
@unless ($internal)
    <div class="sheet">
        <p style="text-align: justify;">
            I declare that the information provided in this form is true, complete and accurate to
            the best of my knowledge.
        </p>

        <p>I further confirm that:</p>

        <p class="clause" style="text-align: justify;">
            a)&nbsp;&nbsp;&nbsp;&nbsp;I will undertake the examination independently, objectively
            and impartially;
        </p>
        <p class="clause" style="text-align: justify;">
            b)&nbsp;&nbsp;&nbsp;&nbsp;I have disclosed all actual, potential or perceived conflicts
            of interest known to me at the time of signing this declaration; and
        </p>
        <p class="clause" style="text-align: justify;">
            c)&nbsp;&nbsp;&nbsp;&nbsp;should any actual, potential or perceived conflict of interest
            arise after submission of this declaration, I will promptly notify UTP in writing and
            provide the relevant details for further assessment.
        </p>

        <table class="details" style="margin-top: 30px;">
            <tr><td class="label">Signature</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">Name</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">Position</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">Organisation</td><td class="sep">:</td><td></td></tr>
            <tr><td class="label">Date</td><td class="sep">:</td><td></td></tr>
        </table>
    </div>
@endunless

{{-- ============================================== thesis receipt confirmation --}}
<div class="sheet">
    <div class="form-code">{{ $formCode }}</div>
    <h2 class="doc-title">Thesis Receipt Confirmation</h2>

    <p style="text-align: right;">Date: <span class="fill"></span></p>

    {!! $deanBlock !!}

    <p>Dear Sir/Madam,</p>

    <p class="subject underline">THESIS RECEIPT CONFIRMATION</p>

    <table class="details">
        <tr><td class="label">Candidate's Name</td><td class="sep">:</td><td>{{ $student?->name ?? '' }}</td></tr>
        <tr><td class="label">Degree</td><td class="sep">:</td><td>{{ $detail->candidate_degree }}</td></tr>
    </table>

    <p>
        I, <b>{{ $examiner->examiner_name }}</b>
        {{ $internal ? 'confirm' : 'hereby confirms' }} that I have received a copy of the
        candidate's thesis in good condition.
    </p>

    <p>Note (if any): <span class="fill" style="min-width: 360px;"></span>.</p>

    <p>Thank you.</p>
    <p>Yours sincerely,</p>

    <div class="sig-line"></div>

    <table class="details">
        <tr><td class="label">Name</td><td class="sep">:</td><td>{{ $examiner->examiner_name }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td></td></tr>
    </table>
</div>

</body>
</html>
