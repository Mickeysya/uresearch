<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Confirmation of Correction</title>@include('hani::re_viva.pdf.forms._style')</head><body>
@include('hani::re_viva.pdf.forms._frame')
@if ($example)<div class="watermark">EXAMPLE</div>@endif
<h1 style="margin-bottom:0">UNIVERSITI TEKNOLOGI PETRONAS</h1><h1>CENTRE FOR GRADUATE STUDIES</h1>
<h2>STUDENT'S DETAILS</h2>
<table class="rows">
@foreach (['Full Name' => $name, 'Student ID' => $matric] as $l => $v)<tr><td style="width:35mm">{{ $l }}</td><td style="width:4mm">:</td><td>{{ $v }}</td></tr>@endforeach
<tr><td>Programme</td><td>:</td><td>{{ $degreeName }}</td></tr>
<tr><td>Study Mode</td><td>:</td><td><span class="box">{{ $studyMode === 'Full Time' ? '☑' : '☐' }}</span> Full Time &nbsp; <span class="box">{{ $studyMode === 'Part Time' ? '☑' : '☐' }}</span> Part Time</td></tr>
@foreach (['RCS Date' => $rcsDate, 'Supervisor' => $supervisor, 'Co-Supervisor' => $coSupervisor, 'Thesis Title' => $thesisTitle] as $l => $v)<tr><td>{{ $l }}</td><td>:</td><td>{{ $v }}</td></tr>@endforeach
</table>
<h2>CONFIRMATION OF CORRECTION TO THESIS</h2>
<p>We hereby certify that we have reviewed the above thesis and therefore verify that the thesis has been corrected/amended as per required.</p>
<table class="two" style="margin-top:10pt">
<tr><td><b>EXAMINER 1:</b> {{ $examiner1 }}</td><td><b>EXAMINER 2:</b> {{ $examiner2 }}</td></tr>
<tr><td>Signature and Official Stamp:<div class="sign"></div></td><td>Signature and Official Stamp:<div class="sign"></div></td></tr>
<tr><td>Date :</td><td>Date :</td></tr>
</table>
<p style="margin-top:14pt"><b>CHAIRMAN, RESEARCH COMPLETION SEMINAR</b> {{ $chairman }}</p>
<p>Signature and Official Stamp:</p><div class="sign"></div><p>Dated:</p>
<div class="pageno">Page 3 of 3</div>
</body></html>
