<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\LetterRenderer;

/**
 * The public pages a QR code (or a printed reference number) leads to, for
 * both of Nureen's letters. No login -- see routes.php. Shows exactly the
 * four facts the spec calls for (who it is for, what it is, when it was
 * issued, who endorsed it) plus Valid/Revoked, nothing else: a letter is
 * not a place to leak a student's matric number, programme or department
 * to an anonymous visitor.
 *
 * "Endorsed by" comes from the same Support\LetterRenderer data the PDF
 * itself was built from, so the page can never name a different person
 * from the letter -- and for an 'automatic' Supervision letter it names no
 * endorser, only the Chair of Department whose approval it followed.
 */
class CertificateVerificationController extends Controller
{
    public function show(string $reference, LetterRenderer $letters)
    {
        $detail = GaCertificationDetail::where('reference_number', $reference)
            ->with('application.student')
            ->first();

        if (! $detail) {
            return $this->notFound('Certificate Verification', 'certificate', $reference);
        }

        $letter = $letters->certificationData($detail->application);

        return view('nureen::letters.verify', [
            'title' => 'Certificate Verification',
            'noun' => 'certificate',
            'reference' => $reference,
            'found' => true,
            'revoked' => $detail->isRevoked(),
            'facts' => [
                'Reference' => $detail->reference_number,
                'Student' => $detail->application->student?->name ?? '—',
                'Letter Type' => ($detail->appointment_type === 'GRA' ? 'Graduate Research Assistant' : 'Graduate Assistant').' Certification Letter',
                'Issue Date' => $letter['date']->format('j F Y'),
                'Endorsed By' => $letter['signatory']['name'] ?? '—',
            ],
        ]);
    }

    public function appointment(string $reference, LetterRenderer $letters)
    {
        $detail = SupervisionDetail::where('letter_reference', $reference)
            ->with('application.student')
            ->first();

        if (! $detail) {
            return $this->notFound('Appointment Letter Verification', 'appointment letter', $reference);
        }

        $letter = $letters->appointmentData($detail->application);

        $facts = [
            'Reference' => $detail->letter_reference,
            'Student' => $detail->application->student?->name ?? '—',
            'Letter Type' => ($detail->appointment_role === SupervisionDetail::ROLE_CO ? 'Co-Supervisor' : 'Main Supervisor').' Appointment Letter',
            'Issue Date' => $letter['date']->format('j F Y'),
        ];

        if ($letter['signatory']) {
            $facts['Endorsed By'] = $letter['signatory']['name'];
        } else {
            $facts['Issued By'] = 'Centre for Graduate Studies'
                .($letter['issuedFollowing'] ? ', following approval by '.$letter['issuedFollowing']->name.', Chair of Department' : '');
        }

        return view('nureen::letters.verify', [
            'title' => 'Appointment Letter Verification',
            'noun' => 'appointment letter',
            'reference' => $reference,
            'found' => true,
            'revoked' => $detail->isRevoked(),
            'facts' => $facts,
        ]);
    }

    protected function notFound(string $title, string $noun, string $reference)
    {
        return view('nureen::letters.verify', [
            'title' => $title,
            'noun' => $noun,
            'reference' => $reference,
            'found' => false,
        ]);
    }
}
