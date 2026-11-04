<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Notifications\ExaminersAppointed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * What each reader of the appointment notice is told to do next.
 *
 * The same notice goes to four kinds of people, and the useful next step
 * differs for each. The candidate can open their own application; nobody
 * else can, so sending everyone there would be sending most of them to a
 * 403. The department's Academic Executive is the one who acts on this:
 * the appointment is their cue to arrange the viva.
 */
class AppointmentNoticeTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function appointment(): Application
    {
        $student = $this->student('22001001', 'cand@test.my');

        return Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'appointment_letter',
            'status' => Application::STATUS_APPROVED,
        ]);
    }

    protected function notice(Application $application): ExaminersAppointed
    {
        return new ExaminersAppointed($application, ['Dr. Chandra Segaran (internal)']);
    }

    /** The Academic Executive is sent where the viva is arranged. */
    public function test_the_academic_executive_is_given_a_link_they_can_use(): void
    {
        $application = $this->appointment();
        $ae = $this->academicExec();

        $mail = $this->notice($application)->toMail($ae);
        $rendered = $mail->render();

        $this->assertStringContainsString('Go to viva scheduling', $rendered);
        $this->assertStringContainsString('arrange the viva', $rendered);

        // A route with no id in it: the per-examiner viva pages are keyed
        // by the other module's own records, which this one cannot supply.
        $expected = Route::has('viva.schedule.index')
            ? route('viva.schedule.index')
            : route('dashboard');

        $this->assertStringContainsString(e($expected), $rendered);
        $this->assertStringNotContainsString(e(route('applications.show', $application)), $rendered);
    }

    /** The candidate still gets their own application. */
    public function test_the_candidate_is_given_their_own_application(): void
    {
        $application = $this->appointment();

        $rendered = $this->notice($application)->toMail($application->student)->render();

        $this->assertStringContainsString('View the appointment documents', $rendered);
        $this->assertStringContainsString(e(route('applications.show', $application)), $rendered);
        $this->assertStringNotContainsString('Go to viva scheduling', $rendered);
    }

    /**
     * Everyone else -- the supervisor, the CGS officer -- gets the facts
     * and no button. There is no page this notice can usefully send them
     * to, and a link to a 403 is worse than none.
     */
    public function test_other_readers_are_given_no_action_at_all(): void
    {
        $application = $this->appointment();

        $rendered = $this->notice($application)->toMail($this->supervisor())->render();

        $this->assertStringContainsString('Dr. Chandra Segaran', $rendered);
        $this->assertStringNotContainsString('Go to viva scheduling', $rendered);
        $this->assertStringNotContainsString('View the appointment documents', $rendered);
    }

    /**
     * The viva page belongs to another module, which may not be installed.
     * The route is asked for by name, so its absence is a fallback rather
     * than a crash when the notice is sent.
     */
    public function test_the_notice_still_sends_when_the_viva_page_is_absent(): void
    {
        $application = $this->appointment();

        // Whichever way this checkout is, rendering must not throw.
        $rendered = $this->notice($application)->toMail($this->academicExec())->render();

        $this->assertNotEmpty($rendered);
    }
}
