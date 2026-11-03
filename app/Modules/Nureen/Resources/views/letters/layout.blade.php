{{--
    The one formal letter layout for Nureen's official letters: the
    Supervision appointment letter (letters/appointment) and the GA/GRA
    certification letter (letters/certification) both extend it. Rendered
    by Dompdf through Support\LetterRenderer, which builds every variable
    below -- including who signs, read from approval_history.

    Self-contained on purpose: no external stylesheet and no web font.
    DejaVu Serif ships with Dompdf and is subset per letter (only the
    glyphs used are embedded). A4 with 2.5 cm side margins; the letterhead
    and the footer are fixed bands inside the top and bottom margins, so they
    repeat on every page (as does a DRAFT or REVOKED watermark) and the body
    always sits in the same space between them.

    Every value is escaped with {{ }} -- names, programme and purpose are
    user input.
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        /*
         * Page geometry. A4 is 29.7 cm tall: the letterhead lives in a fixed
         * band inside the 4.2 cm top margin and the footer in a fixed band
         * inside the 2.6 cm bottom margin, so neither can be pushed by the
         * body, and the body always has the same 22.9 cm between them.
         * Every printed form field has a maximum length (config
         * nureen.ga_certification.fields.*.max, purpose_max) chosen so the
         * longest possible letter still fits that space -- LettersTest
         * renders every letter type at those maximums and asserts one page.
         */
        @page { margin: 4.2cm 2.5cm 2.6cm 2.5cm; }

        /* One size for everything in the body. */
        body {
            font-family: 'DejaVu Serif', serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #000;
        }

        /* ---- letterhead, fixed at the top of every page ---- */
        .letterhead-band { position: fixed; top: -3.0cm; left: 0; right: 0; height: 2.6cm; }
        .letterhead { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; padding: 0; }
        .letterhead .logo { width: 3.6cm; }
        .letterhead .logo img { width: 3.2cm; }
        .letterhead .institution { font-size: 13pt; font-weight: bold; letter-spacing: 0.3pt; }
        .letterhead .centre { font-size: 11pt; margin-top: 1pt; }
        .letterhead .contact { font-size: 8pt; margin-top: 3pt; color: #222; }
        .rule { border: 0; border-top: 1.5pt solid {{ $letterhead['accent'] ?? '#1F3A68' }}; margin: 6pt 0 0; }

        /* ---- addressee (left) with "Our Ref" and date (right) ---- */
        .heading { width: 100%; border-collapse: collapse; margin-bottom: 9pt; }
        .heading > tbody > tr > td { vertical-align: top; padding: 0; }
        /* As narrow as its own (fixed-format) content, so the addressee --
           names, programme, department of any length -- gets the rest. */
        .heading td.refblock { text-align: right; width: 1%; white-space: nowrap; }
        .refblock table { margin-left: auto; border-collapse: collapse; }
        .refblock td { padding: 0 0 0 6pt; text-align: left; white-space: nowrap; }
        .refblock td.label { padding-left: 0; }
        /* As specific as the padding reset above, or it loses to it and a
           long name runs straight into "Our Ref:". */
        .heading td.addressee { padding-right: 14pt; }
        .addressee .name { font-weight: bold; }
        .salutation { margin: 0 0 7pt; }
        .subject { font-weight: bold; text-transform: uppercase; margin: 0 0 7pt; }

        /* ---- body ---- */
        ol.paragraphs { margin: 0; padding-left: 16pt; }
        ol.paragraphs li { margin-bottom: 3pt; text-align: justify; padding-left: 4pt; }
        /* Fixed columns, and long values wrap -- even an unbroken grant code. */
        table.details { width: 100%; border-collapse: collapse; margin: 4pt 0 2pt; table-layout: fixed; }
        table.details td { border: 0.6pt solid #888; padding: 2pt 6pt; vertical-align: top; word-wrap: break-word; }
        table.details td.label { width: 30%; font-weight: bold; }

        /* ---- closing and signature ---- */
        .closing { margin-top: 6pt; }
        .closing p { margin: 0 0 3pt; }
        .signature { margin-top: 12pt; }
        .signature .name { font-weight: bold; }
        .signature .endorsed { margin-top: 3pt; font-style: italic; }
        .cc { margin-top: 8pt; }
        .cc table { border-collapse: collapse; }
        .cc td { padding: 0 6pt 0 0; vertical-align: top; }

        /* ---- footer, fixed at the bottom of every page ---- */
        .footer {
            position: fixed;
            left: 0; right: 0;
            bottom: -2.2cm;
            height: 1.9cm;
            border-top: 0.5pt solid #888;
            padding-top: 5pt;
            font-size: 7.5pt;
            color: #222;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { vertical-align: top; padding: 0; }
        .footer td.qr { width: 2.1cm; }
        .footer td.qr img { width: 1.8cm; height: 1.8cm; }
        .footer .ref { text-align: right; white-space: nowrap; width: 5cm; }

        /* ---- DRAFT / REVOKED, on every page ---- */
        .watermark {
            position: fixed;
            top: 7cm;
            left: -1.5cm;
            width: 19cm;
            text-align: center;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 92pt;
            font-weight: bold;
            letter-spacing: 6pt;
            transform: rotate(-38deg);
        }
        .watermark.draft { color: #d6d6d6; }
        .watermark.revoked { color: #f0b4ae; }
    </style>
</head>
<body>
    @if ($revoked)
        <div class="watermark revoked">REVOKED</div>
    @elseif ($draft)
        <div class="watermark draft">DRAFT</div>
    @endif

    <div class="footer">
        <table>
            <tr>
                @if ($qrDataUri)
                    <td class="qr"><img src="{{ $qrDataUri }}" alt="Verification QR code"></td>
                @endif
                <td>
                    @if ($verificationUrl)
                        Verify this letter at {{ $verificationUrl }}<br>
                    @else
                        A verification link is assigned when this letter is issued.<br>
                    @endif
                    This is a computer-generated letter and does not require a physical signature.
                </td>
                <td class="ref">Ref: {{ $reference ?? 'To be assigned on issue' }}</td>
            </tr>
        </table>
    </div>

    {{-- Letterhead: logo when the configured file exists, text either way.
         Fixed in the top margin, so it is identical on every page and the
         body can never push it. --}}
    <div class="letterhead-band">
        <table class="letterhead">
            <tr>
                @if ($logo)
                    <td class="logo"><img src="{{ $logo }}" alt="{{ $letterhead['institution'] }}"></td>
                @endif
                <td>
                    <div class="institution">{{ $letterhead['institution'] }}</div>
                    <div class="centre">{{ $letterhead['centre'] }}</div>
                    <div class="contact">
                        {{ $letterhead['address'] }}<br>
                        Tel: {{ $letterhead['phone'] }} &nbsp;|&nbsp; Email: {{ $letterhead['email'] }} &nbsp;|&nbsp; {{ $letterhead['website'] }}
                    </div>
                </td>
            </tr>
        </table>
        <hr class="rule">
    </div>

    <table class="heading">
        <tr>
            <td class="addressee">
                <div class="name">{{ $addressee['name'] }}</div>
                @if ($addressee['matric'])<div>Matric No.: {{ $addressee['matric'] }}</div>@endif
                @if ($addressee['programme'])<div>{{ $addressee['programme'] }}</div>@endif
                @if ($addressee['department'])<div>Department of {{ $addressee['department'] }}</div>@endif
            </td>
            <td class="refblock">
                <table>
                    <tr><td class="label">Our Ref:</td><td>{{ $reference ?? 'To be assigned on issue' }}</td></tr>
                    <tr><td class="label">Date:</td><td>{{ $date->format('j F Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="salutation">Dear {{ $salutation }},</p>

    <p class="subject">{{ $subject }}</p>

    <ol class="paragraphs">
        @foreach ($paragraphs as $i => $paragraph)
            <li>
                {{ $paragraph }}
                @if ($i === $detailsAfter && $details)
                    <table class="details">
                        @foreach ($details as $label => $value)
                            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
                        @endforeach
                    </table>
                @endif
            </li>
        @endforeach
    </ol>

    <div class="closing">
        <p>Thank you.</p>
        <p>Yours sincerely,</p>
    </div>

    <div class="signature">
        @if ($signatory)
            <div class="name">{{ $signatory['name'] }}</div>
            <div>{{ $signatory['title'] }}</div>
            <div>Centre for Graduate Studies</div>
            <div class="endorsed">
                @if ($draft)
                    To be digitally endorsed via UResearch 2.0 on issue
                @else
                    Digitally endorsed via UResearch 2.0 on {{ $signatory['at']->format('j F Y, g:i a') }}
                @endif
            </div>
        @else
            {{-- No CGS user acted (Supervision's 'automatic' mode), so no
                 one is named as endorser: the letter says who did act. --}}
            <div class="name">Centre for Graduate Studies</div>
            <div>{{ $letterhead['institution'] }}</div>
            <div class="endorsed">
                Issued via UResearch 2.0 on {{ $date->format('j F Y, g:i a') }}
                @if ($issuedFollowing)
                    following approval by {{ $issuedFollowing->name }}, Chair of Department
                @endif
            </div>
        @endif
    </div>

    @if ($cc)
        <div class="cc">
            <table>
                <tr>
                    <td>cc:</td>
                    <td>
                        @foreach ($cc as $copy)
                            {{ $copy }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                </tr>
            </table>
        </div>
    @endif
</body>
</html>
