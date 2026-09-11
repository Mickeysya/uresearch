@component('mail::message')
# Appointment as Examiner

Dear {{ $examinerName }},

On behalf of the Centre for Graduate Studies (CGS), Universiti Teknologi
PETRONAS, we are pleased to confirm your appointment as an examiner.

Your formal appointment letter is attached to this email as a PDF, and sets
out the full details of the appointment.

If anything in the attached letter looks incorrect, please contact the
Centre for Graduate Studies directly rather than replying to this address.

Thank you,<br>
Centre for Graduate Studies<br>
Universiti Teknologi PETRONAS

<small style="color: #888;">Reference: Appointment Letter — Application #{{ $applicationId }}</small>
@endcomponent
