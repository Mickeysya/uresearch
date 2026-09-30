@component('mail::message')
# Confirmation of Correction to Thesis

Dear {{ $examinerName }},

The candidate below has completed the corrections required at their viva voce
examination. The supervisor and the Chairman of the Viva Voce Examination have
both confirmed this, and the form now needs your signature as examiner.

**Candidate:** {{ $candidate }}
@if ($thesisTitle)
**Thesis:** {{ $thesisTitle }}
@endif
**Reference:** Hardbound Submission #{{ $applicationId }}

@component('mail::button', ['url' => $url])
Sign the Confirmation
@endcomponent

The link opens a page where you upload your signature. It is stamped onto the
form with today's date, alongside the signatures already collected. No account
or password is needed.

The link is private to you and expires in
{{ \App\Modules\Jason\Models\HardboundExaminerSignature::LINK_DAYS }} days. If it
has expired, or you would rather sign on paper, reply to this email and the
Centre for Graduate Studies will arrange it.

Thank you,<br>
Centre for Graduate Studies<br>
Universiti Teknologi PETRONAS
@endcomponent
