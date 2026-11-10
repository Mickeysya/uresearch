<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Summary of Corrections</title>@include('hani::re_viva.pdf.forms._style')</head><body>
@include('hani::re_viva.pdf.forms._frame')
@if ($example)<div class="watermark">EXAMPLE</div>@endif
<div class="pageno">Page <span class="n"></span></div>
<h1>SUMMARY OF CORRECTIONS</h1>
<table class="rows">
<tr><td style="width:32mm">Candidate</td><td>{{ $name }}</td></tr>
<tr><td>Student ID</td><td>{{ $matric }}</td></tr>
<tr><td>Programme</td><td>{{ $degreeName }}</td></tr>
<tr><td>Thesis Title</td><td>{{ $thesisTitle }}</td></tr>
<tr><td>Supervisor</td><td>{{ $supervisor }}</td></tr>
</table>
@foreach (['a. Internal Examiner – Response' => $internalRows, 'b. External Examiner – Response' => $externalRows] as $heading => $rows)
<h2>{{ $heading }}</h2>
<table class="grid">
<thead><tr><th style="width:9mm">No.</th><th>Examiner Comment (Verbatim)</th><th>Action Taken / Correction Made</th><th style="width:22mm">Updated Page(s)</th></tr></thead>
<tbody>
@forelse ($rows as $i => $row)
<tr><td>{{ $i + 1 }}</td><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td></tr>
@empty
@for ($i = 1; $i <= 10; $i++)<tr><td style="height:24pt">{{ $i }}</td><td></td><td></td><td></td></tr>@endfor
@endforelse
</tbody></table>
@endforeach
<div style="page-break-inside:avoid">
<p class="right">Endorsed by</p>
<table class="two">
<tr><td><div class="sign"></div>Signature of Author</td><td><div class="sign"></div>Signature of Supervisor</td></tr>
<tr><td>Permanent address: {{ $address }}@unless($address)<div class="line"></div>@endunless</td><td>Name of Supervisor<br>{{ $supervisor }}</td></tr>
<tr><td>Date: <span class="fill">{{ $date }}</span></td><td>Date: <span class="fill">{{ $date }}</span></td></tr>
</table>
</div>
@foreach ([['EXAMINER 1', $examiner1, 'Date :'], ['EXAMINER 2', $examiner2, 'Date :'], ['CHAIRMAN, RESEARCH COMPLETION SEMINAR', $chairman, 'Dated:']] as [$role, $person, $dated])
<div style="page-break-inside:avoid; margin-top:8pt">
<p><b>{{ $role }}: {{ $person }}</b></p><p>Signature and Official Stamp:</p><div class="sign"></div><p>{{ $dated }}</p>
</div>
@endforeach
</body></html>
