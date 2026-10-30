<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
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

    public function create(Request $request)
    {
        return view('nureen::ga_certification.form', [
            'fields' => config('nureen.ga_certification.fields'),
            'supportingDocumentLabel' => config('nureen.ga_certification.supporting_document_label'),
            'hasSupervisor' => $request->user()->supervisor_id !== null,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $student = $request->user();

        // "Block submission with a clear message instead of creating an
        // application nobody can review" -- the new first stage is
        // addressed to the student's own named supervisor, so there has to
        // be one to address it to.
        if (! $student->supervisor_id) {
            return back()->with('error', 'No supervisor is assigned to your account. Please contact CGS.');
        }

        $data = $request->validate([
            'appointment_type' => ['required', 'in:GA,GRA'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);

        // "The form shows only the fields for the chosen type" -- validated
        // the same way, from config('nureen.ga_certification.fields'), so
        // the form, the validation, the approver's detail view and the
        // letter can never drift on what a GA or GRA row actually carries.
        $typeFields = config("nureen.ga_certification.fields.{$data['appointment_type']}", []);
        $typeRules = [];

        foreach ($typeFields as $key => $field) {
            $typeRules[$key] = [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:255'];
        }

        $typeData = $request->validate($typeRules);

        $documentField = 'supporting_documents';
        $documentLabel = config("nureen.ga_certification.supporting_document_label.{$data['appointment_type']}");

        $documentRules = DocumentStore::manyRules($documentField);
        $documentMessages = DocumentStore::manyMessages($documentField)
            + ["{$documentField}.required" => "Attach {$documentLabel}."];

        $request->validate($documentRules, $documentMessages);

        $application = DB::transaction(function () use ($request, $student, $data, $typeData, $documentField, $documentLabel, $engine, $documents) {
            $application = Application::create([
                'student_id' => $student->id,
                'submitted_by_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            GaCertificationDetail::create([
                'application_id' => $application->id,
                // Snapshotted now, not re-read later -- if the student's
                // supervisor changes afterwards, this application still
                // goes to whoever it was addressed to at submission. See
                // SupervisionDetail::requested_supervisor_id for the same
                // reasoning.
                'supervisor_id' => $student->supervisor_id,
            ] + $data + $typeData);

            $documents->attachMany($application, $request->file($documentField), $documentLabel);

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Certification request #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine, AppointmentVerifier $verifier)
    {
        // Same reasoning as SupervisionController::queue(): the engine's
        // queue is role-scoped, not person-scoped, so the 'supervisor'
        // stage is narrowed to the one person this application actually
        // names. CGS Staff and Senior Director need no such narrowing.
        $queue = $this->queueFor($request, $engine, ['student'],
            function ($query, Stage $stage) use ($request) {
                if ($stage->key !== 'supervisor') {
                    return;
                }

                $query->whereIn('id', GaCertificationDetail::query()
                    ->where('supervisor_id', $request->user()->id)
                    ->select('application_id'));
            });

        $details = GaCertificationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        // "Before CGS staff review, the system automatically checks the
        // student's GA/GRA appointment" -- NOT part of CGS's confirmed
        // process, so gated behind config('nureen.ga_certification.
        // appointment_check_enabled'), default false. When disabled this is
        // not computed at all, so nothing about it can leak into the view.
        $appointmentChecks = config('nureen.ga_certification.appointment_check_enabled')
            ? $details->map(function (GaCertificationDetail $detail) use ($verifier, $queue) {
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
            })
            : collect();

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
     * Overrides the trait's decide(): a 'supervisor'-stage decision must
     * come from the specific supervisor this application names, not merely
     * from someone holding the Supervisor role -- same reasoning as
     * SupervisionController::decide(). The Senior Director's approval is
     * still the final stage, and that is the moment the certificate has to
     * exist -- generate it and attach it as an ApplicationDocument right
     * here, using the same download route and permission check every other
     * upload gets.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();

        if ($stage?->key === 'supervisor' && $detail?->supervisor_id !== $request->user()->id) {
            abort(403, 'This request was not addressed to you.');
        }

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
