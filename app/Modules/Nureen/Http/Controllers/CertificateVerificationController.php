<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaCertificationDetail;

/**
 * The public page a QR code (or a printed reference number) leads to. No
 * login -- see routes.php. Shows exactly the four facts the spec calls
 * for, nothing else: a certificate is not a place to leak a student's
 * matric number, programme or department to an anonymous visitor.
 */
class CertificateVerificationController extends Controller
{
    public function show(string $reference)
    {
        $detail = GaCertificationDetail::where('reference_number', $reference)
            ->with('application.student')
            ->first();

        if (! $detail) {
            return view('nureen::certificate.verify', ['detail' => null, 'reference' => $reference]);
        }

        // The Senior Director's own approval record -- not re-stored on
        // ga_certification_details, since approval_history already has it
        // and is the one place that may not be duplicated from.
        $endorsement = ApprovalHistory::where('application_id', $detail->application_id)
            ->where('stage_key', 'senior_director')
            ->where('decision', 'approved')
            ->first();

        return view('nureen::certificate.verify', [
            'detail' => $detail,
            'reference' => $reference,
            'student' => $detail->application->student,
            'endorsedBy' => $endorsement?->approver,
            'issuedAt' => $endorsement?->created_at,
        ]);
    }
}
