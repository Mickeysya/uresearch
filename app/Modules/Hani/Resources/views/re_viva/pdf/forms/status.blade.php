<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Status of Thesis</title>@include('hani::re_viva.pdf.forms._style')</head><body>
@include('hani::re_viva.pdf.forms._frame')
@if ($example)<div class="watermark">EXAMPLE</div>@endif
<h1>STATUS OF THESIS</h1>
<table class="rows"><tr><td style="width:32mm">Title of thesis</td><td>{{ $thesisTitle }}</td></tr></table>
<p>I <span class="fill" style="min-width:120mm">{{ $example ? mb_strtoupper($name) : '' }}</span></p>
<p>hereby allow my thesis to be placed at the Information Resource Center (IRC) of Universiti Teknologi PETRONAS (UTP) with the following conditions:</p>
<p>1. The thesis becomes the property of UTP</p>
<p>2. The IRC of UTP may make copies of the thesis for academic purposes only.</p>
<p>3. This thesis is classified as &nbsp; <span class="box">{{ $confidential === true ? '☑' : '☐' }}</span> Confidential &nbsp; <span class="box">{{ $confidential === false ? '☑' : '☐' }}</span> Non-confidential</p>
<p>If this thesis is confidential, please state the reason:</p>
<div class="line"></div><div class="line"></div><div class="line"></div>
<p style="margin-top:7pt">The contents of the thesis will remain confidential for <span class="fill" style="min-width:20mm"></span> years.</p>
<p>Remarks on disclosure:</p>
<div class="line"></div><div class="line"></div><div class="line"></div>
<p class="right" style="margin-top:10pt">Endorsed by</p>
<table class="two">
<tr><td><div class="sign"></div>Signature of Author</td><td><div class="sign"></div>Signature of Supervisor</td></tr>
<tr><td>Permanent address: {{ $address }}@unless($address)<div class="line"></div><div class="line"></div>@endunless</td><td>Name of Supervisor<br>@if ($example){{ $supervisor }}@else<div class="line"></div>@endif</td></tr>
<tr><td>Date: <span class="fill">{{ $date }}</span></td><td>Date: <span class="fill">{{ $date }}</span></td></tr>
</table>
</body></html>
