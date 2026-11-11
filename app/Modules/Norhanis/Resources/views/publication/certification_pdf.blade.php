<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        .head { text-align: center; margin-bottom: 18px; }
        .head h1 { font-size: 15px; margin: 0; }
        .head h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        h3 { font-size: 11px; margin: 14px 0 2px; }
        p { margin: 0 0 6px; line-height: 1.5; text-align: justify; }
        ol { margin: 0 0 6px 28px; padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        .detail { margin: 14px 0; }
        .detail td, .detail th { border: 1px solid #1a1a1a; padding: 6px 8px; vertical-align: top; }
        .detail th { text-align: center; font-weight: normal; }
        .signatures { margin-top: 12px; }
        .signatures th { text-align: left; padding: 4px 6px; font-weight: normal; }
        .signatures td { padding: 6px; border-bottom: 1px solid #1a1a1a; vertical-align: bottom; }
        .signatures img { height: 44px; }
        .small { font-size: 9px; color: #444; }
        .foot { margin-top: 18px; font-size: 9px; color: #444; }
    </style>
</head>
<body>
    <div class="head">
        <h1>UNIVERSITI TEKNOLOGI PETRONAS<br>CENTRE FOR GRADUATE STUDIES</h1>
        <h2>AUTHORSHIP CERTIFICATION FORM</h2>
    </div>

    <h3>Authorship</h3>
    <p>An author is generally considered to be an individual or a group who has made substantial intellectual contributions in the:</p>
    <ol>
        <li>Conception and design, or analysis and interpretation of data, AND</li>
        <li>Drafting the articles or revising it critically for intellectual content AND</li>
        <li>Final approval of the version to be published.</li>
    </ol>

    <h3>Certification:</h3>
    <p>The senior or corresponding author is required to certify that all listed authors qualify for authorship according to the above criteria. The author(s) should also certify that no part of the work described has been published before or under consideration for publication elsewhere.</p>

    <h3>Conflict of Interest:</h3>
    <p>All authors must disclose any affiliation with or receiving any financial support from any organization with a direct financial interest in the subject matter or materials discussed in the manuscript. All funding sources supporting the work and all institutional or corporate affiliations are to be acknowledged in a footnote or acknowledgement section.</p>

    <table class="detail">
        <tr><th colspan="2">PROGRAM DETAIL</th></tr>
        <tr>
            <td style="width: 24%;">Title of Paper:</td>
            <td>{{ $detail->title_of_paper }}</td>
        </tr>
        <tr>
            {{-- "Title of Conference" or "Title of Journal", by the request type. --}}
            <td>{{ $detail->titleLabel() }}:</td>
            <td>{{ $detail->title_of_conference_journal }}</td>
        </tr>
    </table>

    <p>I/We hereby certify that the work submitted is in full accordance to the Authorship Policy of Universiti Teknologi PETRONAS and agree on the author order as listed in the manuscript.</p>

    <table class="signatures">
        <tr>
            <th style="width: 42%;">Author's Name</th>
            <th style="width: 30%;">Author's signature</th>
            <th>Date</th>
        </tr>
        @foreach ($detail->authors as $author)
            <tr>
                <td>
                    {{ $loop->iteration }}. {{ $author->author_name }}<br>
                    <span class="small">{{ $author->email }}</span>
                    @if ($author->conflict_disclosure)
                        <br><span class="small">Conflict of interest disclosed: {{ $author->conflict_disclosure }}</span>
                    @endif
                </td>
                <td>
                    @if ($author->signature_png)
                        <img src="data:image/png;base64,{{ $author->signature_png }}" alt="Signature">
                    @endif
                </td>
                <td>{{ $author->signed_at?->format('j M Y, g:ia') }}</td>
            </tr>
        @endforeach
    </table>

    <p class="foot">
        Signed online through UResearch 2.0 for {{ $application->reference() }}. Each author signed from their own
        account or from a personal link sent to the email address shown; the date and time of each signature were
        recorded by the system. Generated {{ now()->format('j M Y, g:ia') }}.
    </p>
</body>
</html>
