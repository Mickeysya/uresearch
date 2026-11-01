<?php

namespace Tests\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Services\CandidacySignatureService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** The cast of a candidacy appeal test: a department with a lecturer, chair, dean, a CGS user and a student. Needs MakesUsers. */
trait BuildsCandidacyWorld
{
    protected CandidacyAppealService $service;

    protected User $student;

    protected User $lecturer;

    protected User $chairUser;

    protected User $deanUser;

    protected User $cgsUser;

    protected StudyCandidacy $candidacy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow('2026-11-01 10:00:00');

        $this->service = app(CandidacyAppealService::class);
        $dept = CandidacyDepartment::create(['code' => 'CIS', 'name' => 'Computer and Information Sciences']);

        $this->student = $this->student();
        $this->lecturer = $this->supervisor();
        $this->chairUser = $this->chair();
        $this->deanUser = $this->user(Role::DEAN_PGR, ['name' => 'Prof. Dean', 'email' => 'dean@test.my']);
        $this->cgsUser = $this->cgs();

        foreach ([[$this->lecturer, 'Lecturer', CandidacyStaffAssignment::ROLE_LECTURER], [$this->chairUser, 'Department Chair', CandidacyStaffAssignment::ROLE_CHAIR], [$this->deanUser, 'Dean of PGR', CandidacyStaffAssignment::ROLE_DEAN]] as [$user, $title, $role]) {
            $profile = CandidacyStaffProfile::create(['user_id' => $user->id, 'full_name' => $user->name, 'designation' => $title, 'department_id' => $dept->id]);
            CandidacyStaffAssignment::create(['department_id' => $dept->id, 'staff_profile_id' => $profile->id, 'role' => $role]);
            $this->service_signatures()->placeholder($profile);
        }

        $this->candidacy = StudyCandidacy::create([
            'student_id' => $this->student->id, 'department_id' => $dept->id, 'programme_start_date' => '2024-01-10',
            'candidacy_expiry_date' => '2027-01-31', 'original_expiry_date' => '2027-01-31', 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function service_signatures(): CandidacySignatureService
    {
        return app(CandidacySignatureService::class);
    }

    protected function profile(User $user): CandidacyStaffProfile
    {
        return CandidacyStaffProfile::where('user_id', $user->id)->firstOrFail();
    }

    /** A complete draft, ready to submit. */
    protected function draft(int $months = 6, bool $withForm = true): CandidacyAppeal
    {
        $draft = $this->service->saveDraft($this->student, [
            'reason' => 'More time to finish the experiments.', 'phase' => 'writing', 'percent_complete' => 60, 'rcs_status' => 'done',
            'requested_months' => $months, 'supervisor_profile_id' => $this->profile($this->lecturer)->id, 'declaration' => true,
            'publications' => [['title' => 'A paper', 'venue' => 'Journal', 'status' => 'published', 'year' => 2025]],
        ]);

        $withForm && CandidacyAppealDocument::create([
            'candidacy_appeal_id' => $draft->id, 'kind' => CandidacyAppealDocument::KIND_OFFICIAL_FORM, 'original_name' => 'form.xlsx',
            'stored_path' => 'candidacy/x.bin', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 10,
            'sha256' => str_repeat('a', 64), 'round_uploaded' => 1, 'uploaded_at' => now(),
        ]);

        return $draft;
    }

    protected function submitted(int $months = 6): CandidacyAppeal
    {
        return $this->service->submit($this->student, $this->draft($months));
    }

    protected function toStage(string $stage, int $months = 6): CandidacyAppeal
    {
        $appeal = $this->submitted($months);
        $order = ['supervisor' => $this->lecturer, 'chair' => $this->chairUser];

        foreach ($order as $key => $user) {
            if ($appeal->current_stage === $stage) {
                break;
            }
            $appeal = $this->service->decide($user, $appeal, 'approve');
        }

        if ($stage === 'dean') {
            $appeal = $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: true);
        }

        return $appeal;
    }
}
