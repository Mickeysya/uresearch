@component('mail::message')
# Appointment as {{ $examinerRole }}

Dear {{ $examinerName }},

On behalf of the Centre for Graduate Studies (CGS), Universiti Teknologi
PETRONAS, we are pleased to confirm your appointment as {{ $examinerRole }}.

Two documents are attached:

- **Appointment Letter**, the terms of your appointment, with the
  acknowledgement slip, conflict of interest declaration and thesis receipt
  confirmation to be returned to our office.
- **Thesis Evaluation Report**, the report form to be completed and
  returned once you have examined the thesis.

If anything in the attached documents looks incorrect, please contact the
Centre for Graduate Studies directly rather than replying to this address.

Thank you,<br>
Centre for Graduate Studies<br>
Universiti Teknologi PETRONAS

<small style="color: #888;">Reference: Appointment Letter, Application #{{ $applicationId }}</small>
@endcomponent
