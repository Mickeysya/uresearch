<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 44px 40px; }
        body { font-family: Tahoma, "DejaVu Sans", sans-serif; font-size: 11px; color: #000; }
        .logo { text-align: center; margin-bottom: 6px; }
        .logo img { height: 56px; }
        h1 { text-align: center; font-size: 15px; letter-spacing: 1px; margin: 0 0 12px; }
        table.routing { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.routing td { border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 5px 7px; vertical-align: top; }
        table.routing td.label { width: 58px; font-weight: bold; }
        table.routing td.sep { width: 10px; }
        .subject { font-weight: bold; text-decoration: underline; margin: 12px 0 8px; }
        .body p { line-height: 1.5; margin: 0 0 8px; }
        .stamp { border: 1px solid #000; padding: 4px 6px; margin-top: 5px; font-size: 8.5px; line-height: 1.35; }
        .stamp .sig { height: 38px; margin-bottom: 3px; text-align: left; }
        .stamp .sig img { height: 38px; }
        .stamp .txt { text-align: left; }
        .approved .sig { text-align: left; margin: 4px 0; }
        .approved { margin-top: 14px; border: 2px solid #000; padding: 8px 10px; }
        .approved .head { font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        img.deansig { height: 44px; }
        .small { font-size: 9px; }
        .footer { text-align: center; font-size: 9px; margin-top: 18px; border-top: 1px solid #000; padding-top: 5px; }
        table.record { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.record th, table.record td { border: 1px solid #000; padding: 5px 6px; text-align: left; vertical-align: top; font-size: 10px; }
        table.record th { font-weight: bold; }
        .pagebreak { page-break-before: always; }
    </style>
</head>
<body>
@php
    $by = fn ($stage) => $approvals->get($stage);
    $stageName = ['supervisor' => 'Lecturer/Supervisor', 'chair' => 'Chair of Department', 'cgs' => 'CGS verification', 'dean' => 'Dean of Postgraduate and Research'];
    $dean = $by('dean');
@endphp
    <div class="logo">@if ($logo)<img src="{{ $logo }}" alt="Universiti Teknologi PETRONAS">@endif</div>
    <h1>MEMORANDUM</h1>

    <table class="routing">
        <tr><td class="label">To</td><td class="sep">:</td><td>{{ $dean?->full_name ?? $appeal->deanProfile?->full_name }}<br>Dean, Postgraduate &amp; Research, Universiti Teknologi PETRONAS</td></tr>
        <tr>
            <td class="label">Through</td><td class="sep">:</td>
            <td>
                {{ $by('chair')?->full_name ?? $appeal->chairProfile?->full_name }} (Chair of Department)
                @if ($by('chair'))<div class="stamp">@if ($signatures->get('chair'))<div class="sig"><img src="{{ $signatures->get('chair') }}" alt=""></div>@endif<div class="txt">{{ $stamp($by('chair')) }}</div></div>@endif
            </td>
        </tr>
        <tr>
            <td class="label">Through</td><td class="sep">:</td>
            <td>
                {{ $by('supervisor')?->full_name ?? $appeal->supervisorProfile?->full_name }} (Supervisor)
                @if ($by('supervisor'))<div class="stamp">@if ($signatures->get('supervisor'))<div class="sig"><img src="{{ $signatures->get('supervisor') }}" alt=""></div>@endif<div class="txt">{{ $stamp($by('supervisor')) }}</div></div>@endif
            </td>
        </tr>
        <tr><td class="label">From</td><td class="sep">:</td><td>{{ $student?->name }} ({{ $student?->studentId }})<br>{{ $appeal->department?->name }}</td></tr>
        <tr><td class="label">Date</td><td class="sep">:</td><td>{{ $appeal->submitted_at?->copy()->setTimezone('Asia/Kuala_Lumpur')->format('j F Y') }} · Ref {{ $appeal->appeal_no }}</td></tr>
    </table>

    <p class="subject">Appeal for Extension of Candidacy</p>

    <div class="body">
        <p>Dear {{ $dean?->full_name ?? 'Sir/Madam' }},</p>
        <p>I would like to appeal for an extension of my study candidacy of <strong>{{ $appeal->requested_months }} month(s)</strong>. My candidacy currently expires on {{ $appeal->expiry_at_submission?->format('j F Y') }}.</p>
        <p>{!! nl2br(e($appeal->reason)) !!}</p>
        <p class="small">Progress: {{ \App\Modules\Chloe\Models\CandidacyAppeal::phases()[$appeal->phase] ?? $appeal->phase }}@if ($appeal->percent_complete !== null), {{ $appeal->percent_complete }}% of the thesis written @endif; RCS {{ $appeal->rcs_status === 'done' ? 'done' : 'pending' }}.</p>
        <p>Yours sincerely,</p>
        <div class="stamp">Digitally submitted by {{ $student?->name }} ({{ $student?->studentId }}) on {{ $when($appeal->submitted_at) }} (GMT+8) · Ref {{ $appeal->appeal_no }} · Declaration accepted</div>
    </div>

    @if ($dean)
        <div class="approved">
            <div class="head">APPROVED: {{ $appeal->approved_months }} month(s). New candidacy expiry date: {{ $appeal->new_expiry_date?->format('j F Y') }}</div>
            @if ($signatures->get('dean'))<div class="sig"><img class="deansig" src="{{ $signatures->get('dean') }}" alt=""></div>@endif
            <div class="stamp"><div class="txt">{{ $stamp($dean) }}</div></div>
        </div>
    @endif

    <div class="footer">Universiti Teknologi PETRONAS · Centre for Graduate Studies</div>

    <div class="pagebreak"></div>
    <div class="logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</div>
    <h1>ENDORSEMENT AND APPROVAL RECORD</h1>
    <p class="small">Appeal {{ $appeal->appeal_no }} · {{ $student?->name }} ({{ $student?->studentId }}) · times are Malaysia time (GMT+8). A code can be checked with the verification command of the system.</p>

    <table class="record">
        <tr><th>Step</th><th>Name and designation</th><th>Decision</th><th>Date and time</th><th>Verification code</th></tr>
        @foreach ($approvals as $row)
            <tr>
                <td>{{ $stageName[$row->stage] ?? $row->stage }}@if ($row->round > 1) (round {{ $row->round }})@endif</td>
                <td>{{ $row->full_name }}<br>{{ $row->designation }}</td>
                <td>{{ $row->decision === 'verified' ? 'Verified' : 'Approved' }}</td>
                <td>{{ $when($row->decided_at) }}</td>
                <td>{{ $row->verification_code }}</td>
            </tr>
        @endforeach
    </table>
    <div class="footer">Universiti Teknologi PETRONAS · Centre for Graduate Studies</div>
</body>
</html>
