<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Support\AppointmentVerifier;
use App\Modules\Nureen\Support\CertificateReferenceGenerator;
use App\Modules\Nureen\Support\Contracts\GeneratesQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class CertificationController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'ga_certification';
    }

    public function create()
    {
        return view('nureen::ga_certification.form');
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'appointment_type' => ['required', 'in:GA,GRA'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'purpose' => ['required', 'string', 'max:255'],
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            GaCertificationDetail::create(['application_id' => $application->id] + $data);

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Certification request #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine, AppointmentVerifier $verifier)
    {
        $queue = $this->queueFor($request, $engine, ['student']);

        $details = GaCertificationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        // "Before CGS staff review, the system automatically checks the
        // student's GA/GRA appointment" -- computed here, for every row in
        // the queue, not only at the moment the letter is generated.
        // Informational: nothing here stops CGS approving or rejecting
        // either way (config('nureen.ga_certification') carries no
        // enforcement flag on purpose).
        $appointmentChecks = $details->map(function (GaCertificationDetail $detail) use ($verifier, $queue) {
            $application = $queue['applications']->firstWhere('id', $detail->application_id);

            if (! $application?->student) {
                return ['status' => AppointmentVerifier::NOT_FOUND, 'record' => null];
            }

            return $verifier->verify(
                $application->student,
                $detail->appointment_type,
                $detail->period_start,
                $detail->period_end,
            );
        });

        return view('nureen::ga_certification.queue', $queue + [
            'details' => $details,
            'appointmentChecks' => $appointmentChecks,
        ]);
    }

    /**
     * Every certificate ever issued, Valid or Revoked -- reachable from the
     * queue's tools slot (ga_certification/_tools.blade.php). The pending
     * queue above only ever lists applications still awaiting a decision,
     * so once the Senior Director approves one and the certificate exists,
     * this is the only place left to find it and revoke it.
     */
    public function issued(Request $request)
    {
        $details = GaCertificationDetail::whereNotNull('reference_number')
            ->with('application.student')
            ->orderByDesc('id')
            ->paginate(20);

        return view('nureen::ga_certification.issued', ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): the Senior Director's approval is the
     * final stage, and that is the moment the certificate has to exist --
     * generate it and attach it as an ApplicationDocument right here, using
     * the same download route and permission check every other upload gets.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED) {
            $this->generateCertificate($decided, $request->user(), $documents);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    protected function generateCertificate(Application $application, $endorsedBy, DocumentStore $documents): void
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->firstOrFail();

        $detail->update(['reference_number' => app(CertificateReferenceGenerator::class)->generate()]);

        $verificationUrl = route('certificate.verify', $detail->reference_number);
        $qr = app(GeneratesQrCode::class)->dataUri($verificationUrl);

        $pdf = Pdf::loadView('nureen::ga_certification.certificate_pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'endorsedBy' => $endorsedBy,
            'verificationUrl' => $verificationUrl,
            'qrDataUri' => $qr,
        ]);

        $certificate = $documents->storeGenerated(
            $application,
            $pdf->output(),
            "certification-{$application->id}.pdf",
            'GA/GRA Certification Letter',
        );

        // "Generate, format, and dispatch" -- this is the dispatch. The
        // generic ApplicationDecided mail announces the approval but carries
        // nothing, so without this the student has to go and find the
        // download. The letter itself stays an ApplicationDocument behind the
        // authorised route; the email is a copy, not the record.
        $application->student?->notify(new CertificationIssued($application, $certificate));
    }

    /**
     * Marks the certificate invalid without touching the application the
     * way an approval chain would -- the application stays approved, this
     * is only about whether the PDF already issued is still trusted. The
     * public verification page is what this is actually for.
     */
    public function revoke(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = GaCertificationDetail::where('application_id', $application->id)->firstOrFail();

        abort_if($detail->reference_number === null, 404, 'No certificate has been generated for this application.');

        $data = $request->validate([
            'revoked_reason' => ['required', 'string', 'max:255'],
        ], [
            'revoked_reason.required' => 'Say why this certificate is being revoked — it is shown nowhere public, but is kept for the record.',
        ]);

        $detail->update([
            'revoked_at' => now(),
            'revoked_reason' => $data['revoked_reason'],
        ]);

        return back()->with('status', "Certificate {$detail->reference_number} revoked.");
    }
}
