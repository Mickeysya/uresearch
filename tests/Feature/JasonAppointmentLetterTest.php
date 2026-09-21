<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Listeners\RecordExaminerPackDelivery;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\PoolExaminer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Appointment Letter — the two rules that live outside the workflow engine,
 * and so have nothing else watching them.
 *
 * 1. An examiner the Dean has appointed is unavailable for three months.
 *    Nomination no longer has a form of its own -- see AppointmentLetterWorkflow's
 *    class doc comment -- so these build the panel straight into the
 *    database via nomination() below and check the rule holds regardless.
 * 2. A pack counts as delivered only when the mail transport accepts it.
 *    Dispatch happens after the engine has committed the Dean's approval, so
 *    a failure there leaves an approved nomination whose examiner received
 *    nothing — which is exactly what the Issued Appointments page is for.
 */
class JasonAppointmentLetterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml asks for the sync queue, but an env var already set in
        // the container wins over it, so QUEUE_CONNECTION stays on redis and
        // a queued mailable would be handed to the dev worker instead of
        // being sent here. AppointmentLetterMail is ShouldQueue, and these
        // tests are about what happens once it is actually sent.
        config(['queue.default' => 'sync']);
    }

    protected function chair(): User
    {
        return User::firstOrCreate(['email' => 'chair@test.my'], [
            'name' => 'Dr Chair',
            'password' => 'password',
            'role' => Role::CHAIR,
            'department' => 'Civil Engineering',
        ]);
    }

    protected function candidate(string $email = 'cand@test.my'): User
    {
        return User::firstOrCreate(['email' => $email], [
            'name' => 'Ahmad Danial',
            'password' => 'password',
            'role' => Role::STUDENT,
            // Unique per candidate: the column is unique, and some of these
            // tests build more than one.
            'matric_no' => '2200'.substr(md5($email), 0, 4),
            'department' => 'Civil Engineering',
        ]);
    }

    protected function poolExaminer(string $type, string $email): PoolExaminer
    {
        return PoolExaminer::create([
            'name' => 'Prof '.ucfirst($type),
            'examiner_type' => $type,
            'institution' => $type === AppointmentExaminer::TYPE_INTERNAL ? 'UTP' : 'UM',
            'email' => $email,
            'expertise' => 'Structural Engineering',
            'is_active' => true,
        ]);
    }

    /* -----------------------------------------------------------------
     | The three-month cooldown
     |------------------------------------------------------------------*/

    public function test_an_examiner_is_available_until_an_appointment_is_made(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_INTERNAL, 'int@test.my');

        $this->assertTrue($examiner->isAvailable());
        $this->assertNull($examiner->unavailableLabel());
    }

    public function test_an_appointment_makes_an_examiner_unavailable_for_three_months(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_INTERNAL, 'int@test.my');
        $application = $this->nomination($examiner);

        AppointmentExaminer::where('application_id', $application->id)
            ->update(['appointed_at' => now()]);

        $this->assertFalse($examiner->fresh()->isAvailable());
        $this->assertStringContainsString('on an appointment until', $examiner->fresh()->unavailableLabel());
    }

    public function test_the_cooldown_lapses_on_its_own(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_INTERNAL, 'int@test.my');
        $application = $this->nomination($examiner);

        // One day past the cooldown: nobody has to reinstate them.
        AppointmentExaminer::where('application_id', $application->id)->update([
            'appointed_at' => now()->subMonths(PoolExaminer::COOLDOWN_MONTHS)->subDay(),
        ]);

        $this->assertTrue($examiner->fresh()->isAvailable());
    }

    public function test_a_nomination_only_counts_once_the_dean_has_appointed(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_INTERNAL, 'int@test.my');
        $this->nomination($examiner);

        // Nominated but not yet approved -- appointed_at is still null, so
        // the cooldown has not started.
        $this->assertTrue($examiner->fresh()->isAvailable());
    }

    /* -----------------------------------------------------------------
     | Pack delivery
     |------------------------------------------------------------------*/

    public function test_a_pack_is_marked_delivered_only_when_the_transport_accepts_it(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_EXTERNAL, 'ext@test.my');
        $application = $this->nomination($examiner);
        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();

        $this->assertFalse($row->packSent());

        Mail::to($row->examiner_email)->send(
            new AppointmentLetterMail($application, $row, [['name' => 'letter.pdf', 'contents' => '%PDF-1.4']])
        );

        $this->assertTrue($row->fresh()->packSent());
    }

    public function test_mail_from_other_modules_is_left_alone(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_EXTERNAL, 'ext@test.my');
        $application = $this->nomination($examiner);
        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();

        // Any other message in the portal reaches the same listener; it must
        // ignore everything not carrying the examiner header.
        Mail::raw('Something else entirely', fn ($m) => $m->to('someone@test.my')->subject('Other'));

        $this->assertFalse($row->fresh()->packSent());
    }

    /* -----------------------------------------------------------------
     | The two screens
     |------------------------------------------------------------------*/

    public function test_cgs_sees_an_undelivered_pack_and_can_resend_it(): void
    {
        $examiner = $this->poolExaminer(AppointmentExaminer::TYPE_EXTERNAL, 'ext@test.my');
        $application = $this->nomination($examiner);
        $application->update(['status' => Application::STATUS_APPROVED]);

        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();
        $row->update(['appointed_at' => now()]);

        $cgs = User::create([
            'name' => 'Puan Waheeda',
            'email' => 'cgs@test.my',
            'password' => 'password',
            'role' => Role::NON_EXEC_CGS,
        ]);

        $this->actingAs($cgs)
            ->get(route('appointment-letter.issued'))
            ->assertOk()
            ->assertSee('Not delivered')
            ->assertSee('Resend');

        // Nothing was ever prepared for this nomination, so there is nothing
        // to resend -- and CGS is told that rather than shown a false success.
        $this->actingAs($cgs)
            ->post(route('appointment-letter.resend', [$application, $row]))
            ->assertSessionHas('error');
    }

    public function test_a_chair_cannot_reach_the_issued_page(): void
    {
        $this->actingAs($this->chair())
            ->get(route('appointment-letter.issued'))
            ->assertForbidden();
    }

    /**
     * A nomination for one examiner, straight into the database -- these
     * tests are about what happens to it afterwards, not about the form.
     */
    protected function nomination(PoolExaminer $examiner): Application
    {
        $application = Application::create([
            'student_id' => $this->candidate('cand-'.$examiner->id.'@test.my')->id,
            'submitted_by_id' => $this->chair()->id,
            'module_type' => 'appointment_letter',
            'status' => Application::STATUS_DRAFT,
        ]);

        AppointmentDetail::create(['application_id' => $application->id]);
        AppointmentExaminer::create(['application_id' => $application->id] + $examiner->toSnapshot());

        return $application;
    }

}
