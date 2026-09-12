<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\CertificationIssued;
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

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine);

        $details = GaCertificationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('nureen::ga_certification.queue', $queue + ['details' => $details]);
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

        $pdf = Pdf::loadView('nureen::ga_certification.certificate_pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'endorsedBy' => $endorsedBy,
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
}
