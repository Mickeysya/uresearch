{{-- The examiner's re-evaluation email. Rendered twice by ReVivaCorrectionLetter:
     as HTML ($plain false: inline links) and as plain text ($plain true: the
     button components print "Label: URL" on their own line). Markdown, so no
     indentation inside the message. --}}
<x-mail::message>
# Dear {{ $examinerName }},

@if ($resent)
The Centre for Graduate Studies is sending this letter again, with a new link to the documents. Please use the link in this email; an earlier one may have expired.

@endif
Please find attached the Centre for Graduate Studies' letter and the Thesis Re-evaluation Report form (UTP/CGS/024) for the corrected thesis of {{ $studentName }}.

@if ($panelLink)
The student's documents are too large to attach. Open them through the secure link below; you will be asked to confirm this email address and enter a one-time code. The link works until {{ $expires }}.

<x-mail::button :url="$panelLink" color="primary">
Open the corrected thesis
</x-mail::button>

@else
The Confirmation of Correction, the Summary of Corrections and the re-corrected thesis are attached too.

@endif
@if ($plain)
Please return the completed report and the signed letter by {{ $due }}, through My Re-evaluations in UResearch or by email to the contacts in the letter.
@else
Please return the completed report and the signed letter by {{ $due }}, through [My Re-evaluations]({{ $myUrl }}) in UResearch or by email to the contacts in the letter.
@endif

<x-mail::button :url="$myUrl" color="primary">
Open My Re-evaluations
</x-mail::button>

@if ($setupUrl)
@if ($plain)
Your UResearch account has been created with this email address. Set your password before you sign in.
@else
Your UResearch account has been created with this email address. [Set your password]({{ $setupUrl }}) before you sign in.
@endif

<x-mail::button :url="$setupUrl" color="primary">
Set your password
</x-mail::button>

@endif
Sent by the Centre for Graduate Studies through UResearch 2.0.

Regards,<br>
{{ config('app.name') }}
</x-mail::message>
