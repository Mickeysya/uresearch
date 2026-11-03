<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1a1a1a; }
        .code { text-align: right; font-size: 9px; }
        h1 { font-size: 17px; margin: 2px 0 10px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        .detail td { border: 1px solid #1a1a1a; padding: 5px 7px; }
        .detail td.key { width: 22%; font-weight: bold; background: #c8c8c8; }
        .criterion { margin-top: 12px; }
        .criterion td { vertical-align: top; padding: 0; }
        .criterion .ask { width: 34%; padding-right: 10px; }
        .criterion h2 { font-size: 10.5px; margin: 0 0 4px; text-transform: uppercase; }
        .criterion ul { margin: 0; padding-left: 14px; }
        .scale td { border: 1px solid #1a1a1a; text-align: center; padding: 3px 0; width: 20%; }
        .scale td.on { background: #1a1a1a; color: #fff; font-weight: bold; }
        .comment { border: 1px solid #1a1a1a; border-top: 0; padding: 6px 8px; min-height: 30px; }
        .recommend { margin-top: 14px; }
        .recommend td { padding: 4px 6px; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1px solid #1a1a1a; text-align: center; line-height: 12px; font-size: 10px; }
        .sign { margin-top: 16px; }
        .sign td { vertical-align: bottom; padding: 4px 6px; }
        .sign img { height: 44px; }
        .small { font-size: 9px; color: #444; }
    </style>
</head>
<body>
    <div class="code">UTP/CGS/040</div>
    <h1>PAPER EVALUATION SHEET</h1>

    <table class="detail">
        <tr><td class="key">TITLE</td><td>{{ $detail->title_of_paper }}</td></tr>
        <tr><td class="key">AUTHOR</td><td>{{ $detail->authors->pluck('author_name')->implode(', ') }}</td></tr>
        <tr><td class="key">CONFERENCE</td><td>{{ $detail->title_of_conference_journal }}</td></tr>
        <tr><td class="key">VENUE</td><td>{{ $detail->venue }}</td></tr>
        <tr><td class="key">DATE</td><td>{{ $detail->conference_start_date->format('j M Y') }} to {{ $detail->conference_end_date->format('j M Y') }}</td></tr>
    </table>

    @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::CRITERIA as $key => [$heading, $questions])
        <table class="criterion">
            <tr>
                <td class="ask">
                    <h2>{{ $heading }}</h2>
                    <ul>
                        @foreach ($questions as $question) <li>{{ $question }}</li> @endforeach
                    </ul>
                </td>
                <td>
                    <table class="scale">
                        <tr>
                            @foreach ([1, 2, 3, 4, 5] as $score)
                                <td class="{{ (int) $evaluation->{$key.'_rating'} === $score ? 'on' : '' }}">{{ $score }}</td>
                            @endforeach
                        </tr>
                    </table>
                    <div class="comment">{{ $evaluation->{$key.'_comment'} }}</div>
                </td>
            </tr>
        </table>
    @endforeach

    <table class="criterion">
        <tr>
            <td class="ask">
                <h2>Additional Comments</h2>
                <ul>
                    @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::ADDITIONAL_QUESTIONS as $question) <li>{{ $question }}</li> @endforeach
                </ul>
            </td>
            <td><div class="comment" style="border-top: 1px solid #1a1a1a; min-height: 60px;">{{ $evaluation->additional_comments }}</div></td>
        </tr>
    </table>

    <table class="recommend">
        <tr>
            @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::recommendations() as $value => $label)
                <td><span class="box">{{ $evaluation->recommendation === $value ? 'X' : '' }}</span> {{ $label }}</td>
            @endforeach
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td style="width: 16%;">Evaluated By:</td>
            <td style="width: 44%; border-bottom: 1px solid #1a1a1a;">
                @if ($evaluation->signature_png)
                    <img src="data:image/png;base64,{{ $evaluation->signature_png }}" alt="Signature">
                @endif
            </td>
            <td style="width: 20%; text-align: right;">Date of Evaluation:</td>
            <td style="border-bottom: 1px solid #1a1a1a;">{{ $evaluation->signed_at?->format('j M Y') }}</td>
        </tr>
        <tr>
            <td></td>
            <td>( {{ $evaluation->reviewer_name }} )<br><span class="small">{{ $evaluation->reviewer_email }}</span></td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <p class="small" style="margin-top: 14px;">
        Rating: 1 – Far Below Requirements &nbsp; 2 – Below Requirements &nbsp; 3 – Meets Requirements &nbsp;
        4 – Exceeds Requirements &nbsp; 5 – Far Exceeds Requirements
    </p>
    <p class="small">
        Completed online through UResearch 2.0 for {{ $application->reference() }}, from a personal link sent to the
        reviewer's email address shown. Signed {{ $evaluation->signed_at?->format('j M Y, g:ia') }}.
    </p>
</body>
</html>
