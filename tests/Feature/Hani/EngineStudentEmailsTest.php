<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Notifications\ApplicationReturned;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The engine's "Progress Update" emails to a viva student about their
 * examiner nomination are cancelled (ModuleProvider); the return email and
 * every other recipient and module are left alone.
 */
class EngineStudentEmailsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_nomination_progress_updates_to_the_student_are_cancelled_and_nothing_else(): void
    {
        $student = $this->user(Role::STUDENT, ['email' => 'candidate@example.com']);
        $supervisor = $this->user(Role::SUPERVISOR, ['email' => 'supervisor@example.com']);
        $stage = new Stage('academic_exec', 'Academic Executive', Role::ACADEMIC_EXEC, 'approved');
        $application = fn (string $module) => Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $supervisor->id,
            'module_type' => $module, 'status' => Application::STATUS_PENDING,
        ]);
        $sending = fn ($to, $notification) => Event::until(new NotificationSending($to, $notification, 'mail'));

        $nomination = $application('examiner_nomination');
        $this->assertFalse($sending($student, new ApplicationDecided($nomination, $stage, true)), 'Progress Update to the student');
        $this->assertNotFalse($sending($supervisor, new ApplicationDecided($nomination, $stage, true)), 'other recipients');
        $this->assertNotFalse($sending($student, new ApplicationDecided($nomination, $stage, false)), 'Not Approved');
        $this->assertNotFalse($sending($student, new ApplicationReturned($nomination, $stage, $stage, 'A list went back.')), 'the return email');

        $travel = $application('travel');
        $this->assertNotFalse($sending($student, new ApplicationDecided($travel, $stage, true)), 'other modules');
    }
}
