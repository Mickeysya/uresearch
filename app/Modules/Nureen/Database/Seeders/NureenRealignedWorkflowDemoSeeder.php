<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\AppointmentLetterIssued;
use App\Modules\Nureen\Notifications\AppointmentLetterIssuedToCgs;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Support\AppointmentReferenceGenerator;
use App\Modules\Nureen\Support\CertificateReferenceGenerator;
use App\Modules\Nureen\Support\Contracts\GeneratesQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Demo applications that exercise the three chains CGS confirmed in this
 * realignment -- GA Extension in 'cgs' mode, Supervision through the Chair
 * with an automatic letter for both a Main and a Co-Supervisor, and
 * Certification for both a GA and a GRA.
 *
 * database/seeders/DemoDataSeeder.php (repo root, shared by all six people)
 * still only knows the OLD chains for these three modules -- its CHAINS
 * constant and each module's $outcomes arrays predate this realignment, and
 * re-running `php artisan demo:seed` will throw once a chain in its default
 * config mode is shorter than what that file expects to decide through. See
 * SHARED_CHANGES.md. Rather than edit that shared file solo, this is
 * Nureen's own seeder for demoing the NEW chains specifically -- run
 * DemoDataSeeder first for everything else, this on top of it.
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenRealignedWorkflowDemoSeeder"
 *
 * Every application goes through WorkflowEngine::submit()/decide(), never a
 * direct write to applications.status or current_stage (rule 1). The letter/
 * certificate generation that a real decide() request triggers (see
 * SupervisionController::decide()/CertificationController::decide()) is
 * reproduced here with the same support classes a real request would use
 * (AppointmentReferenceGenerator, CertificateReferenceGenerator,
 * DocumentStore, Dompdf), not re-derived -- a console command has no HTTP
 * request to hand the real controller, so this is the seeder's own call
 * into the same pieces, kept deliberately short.
 *
 * Matched on a fixed set of demo emails via updateOrCreate, so running it
 * twice adds a fresh batch of applications on top rather than erroring on
 * duplicate users.
 */
class NureenRealignedWorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Real notifications are not the point of a demo seed run -- avoid
        // flooding Mailpit every time this is re-run.
        Notification::fake();

        $engine = app(WorkflowEngine::class);

        $supervisor = $this->makeUser('Dr. Aisyah Rahman', 'supervisor@utp.edu.my', Role::SUPERVISOR, ['department' => 'Computing']);
        $coSupervisor = $this->makeUser('Dr. Faizal Hakimi', 'co-supervisor-demo@utp.edu.my', Role::SUPERVISOR, ['department' => 'Computing']);
        $chair = $this->makeUser('Dr. Lim Wei Chun', 'chair@utp.edu.my', Role::CHAIR, ['department' => 'Computing']);
        $cgs = $this->makeUser('M Syahmi Ifwat M Jafri', 'cgs@utp.edu.my', Role::NON_EXEC_CGS, ['department' => 'CGS']);
        $director = $this->makeUser('En Zulkifly', 'director@utp.edu.my', Role::SENIOR_DIRECTOR_CGS, ['department' => 'CGS']);

        $gaExtStudent = $this->makeUser('Nurul Izzah', 'ga-extension-demo@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22090001', 'department' => 'Computing', 'supervisor_id' => $supervisor->id,
        ]);
        $mainStudent = $this->makeUser('Amirul Hakim', 'supervision-main-demo@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22090002', 'department' => 'Computing',
        ]);
        $coStudent = $this->makeUser('Balqis Aina', 'supervision-co-demo@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22090003', 'department' => 'Computing', 'supervisor_id' => $supervisor->id,
        ]);
        $gaCertStudent = $this->makeUser('Chong Wei Xuan', 'ga-cert-demo@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22090004', 'department' => 'Computing', 'supervisor_id' => $supervisor->id,
        ]);
        $graCertStudent = $this->makeUser('Divya Shanmugam', 'gra-cert-demo@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22090005', 'department' => 'Computing', 'supervisor_id' => $supervisor->id,
        ]);

        $this->gaExtensionCgsMode($engine, $gaExtStudent, $supervisor, $cgs);
        $this->supervisionMain($engine, $mainStudent, $supervisor, $chair, $cgs);
        $this->supervisionCo($engine, $coStudent, $coSupervisor, $chair, $cgs);
        $this->certification($engine, $gaCertStudent, $supervisor, $cgs, $director, 'GA');
        $this->certification($engine, $graCertStudent, $supervisor, $cgs, $director, 'GRA');

        $this->command?->info(
            'Seeded GA Extension (cgs mode), Supervision (Main + Co, automatic) '.
            'and Certification (GA + GRA) demo applications under the realigned chains.'
        );
    }

    protected function makeUser(string $name, string $email, string $role, array $attributes = []): User
    {
        return User::updateOrCreate(['email' => $email], $attributes + [
            'name' => $name,
            'role' => $role,
            'password' => Hash::make('password'),
        ]);
    }

    protected function gaExtensionCgsMode(WorkflowEngine $engine, User $student, User $supervisor, User $cgs): void
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_DRAFT,
        ]);

        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => now()->addMonth(),
            'requested_new_end_date' => now()->addMonths(7),
            'reason_for_extension' => 'Fieldwork delayed by two semesters; the remaining analysis needs the assistantship to continue.',
            'final_approver_snapshot' => 'cgs',
        ]);

        $engine->submit($application);
        $engine->decide($application, $supervisor, 'approve');
        // Final in 'cgs' mode -- no Senior Director stage on this chain.
        $engine->decide($application, $cgs, 'approve');
    }

    protected function supervisionMain(WorkflowEngine $engine, User $student, User $supervisor, User $chair, User $cgs): void
    {
        $detail = $this->submitSupervision($engine, $student, $supervisor, SupervisionDetail::ROLE_MAIN, 'new');

        $engine->decide($detail->application, $supervisor, 'approve');
        $decided = $engine->decide($detail->application, $chair, 'approve');

        $detail->update(['previous_supervisor_id' => $student->supervisor_id]);
        $student->update(['supervisor_id' => $supervisor->id]);

        $this->issueAppointmentLetter($decided, $detail->fresh(), $chair, $supervisor, $cgs);
    }

    protected function supervisionCo(WorkflowEngine $engine, User $student, User $coSupervisor, User $chair, User $cgs): void
    {
        $detail = $this->submitSupervision($engine, $student, $coSupervisor, SupervisionDetail::ROLE_CO, 'new');

        $engine->decide($detail->application, $coSupervisor, 'approve');
        $decided = $engine->decide($detail->application, $chair, 'approve');

        // Co-Supervisor: users.supervisor_id is never touched.
        $this->issueAppointmentLetter($decided, $detail->fresh(), $chair, $coSupervisor, $cgs);
    }

    protected function submitSupervision(WorkflowEngine $engine, User $student, User $supervisor, string $role, string $type): SupervisionDetail
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => Application::STATUS_DRAFT,
        ]);

        $detail = SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => $role === SupervisionDetail::ROLE_CO
                ? 'Co-supervision requested to cover the statistical methods component.'
                : 'Shared research interest in reservoir simulation and modelling.',
            'appointment_role' => $role,
            'appointment_type' => $type,
            'cgs_stage_snapshot' => 'automatic',
        ]);

        $engine->submit($application);

        return $detail->fresh()->load('application');
    }

    protected function issueAppointmentLetter(Application $application, SupervisionDetail $detail, User $endorsedBy, User $supervisor, User $cgs): void
    {
        $documents = app(DocumentStore::class);

        $detail->update([
            'letter_reference' => app(AppointmentReferenceGenerator::class)->generate(),
            'letter_issued_at' => now(),
        ]);

        $pdf = Pdf::loadView('nureen::supervision.appointment_letter_pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'supervisor' => $supervisor,
            'endorsedBy' => $endorsedBy,
        ]);

        $letter = $documents->storeGenerated(
            $application,
            $pdf->output(),
            "appointment-{$application->id}.pdf",
            'Supervisor Appointment Letter',
        );

        $application->student?->notify(new AppointmentLetterIssued($application, $detail, $letter, $supervisor));
        $cgs->notify(new AppointmentLetterIssuedToCgs($application, $detail));
    }

    protected function certification(WorkflowEngine $engine, User $student, User $supervisor, User $cgs, User $director, string $type): void
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_DRAFT,
        ]);

        $fields = $type === 'GA' ? [
            'course_code' => 'CCB2012',
            'course_name' => 'Process Control',
            'course_owner_name' => $supervisor->name,
            'semester' => 'Sept 2025',
        ] : [
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'project_leader_name' => $supervisor->name,
            'semester' => 'Sept 2025',
        ];

        $detail = GaCertificationDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'appointment_type' => $type,
            'period_start' => now()->subMonths(6),
            'period_end' => now(),
            'purpose' => $type === 'GA' ? 'Scholarship application supporting document' : null,
        ] + $fields);

        $engine->submit($application);
        $engine->decide($application, $supervisor, 'approve');
        $engine->decide($application, $cgs, 'approve');
        $decided = $engine->decide($application, $director, 'approve');

        $detail->update(['reference_number' => app(CertificateReferenceGenerator::class)->generate()]);

        $verificationUrl = route('certificate.verify', $detail->reference_number);
        $qr = app(GeneratesQrCode::class)->dataUri($verificationUrl);

        $pdf = Pdf::loadView('nureen::ga_certification.certificate_pdf', [
            'application' => $decided,
            'detail' => $detail,
            'student' => $student,
            'endorsedBy' => $director,
            'verificationUrl' => $verificationUrl,
            'qrDataUri' => $qr,
        ]);

        $certificate = app(DocumentStore::class)->storeGenerated(
            $decided,
            $pdf->output(),
            "certification-{$decided->id}.pdf",
            'GA/GRA Certification Letter',
        );

        $decided->student?->notify(new CertificationIssued($decided, $certificate));
    }
}
