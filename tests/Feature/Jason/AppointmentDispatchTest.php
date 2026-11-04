<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\ModuleProvider;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Support\AppointmentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Dispatch dates, answered in the nomination chain's own terms.
 *
 * The keys are theirs, not this module's: the nomination's application id
 * and the examiner's id in the examiner records. Getting that wrong is the
 * easy mistake here, because this module holds three other ids that look
 * plausible -- its own application id, its appointment_examiners row id,
 * and pool_examiner_id, which belongs to its own availability pool.
 */
class AppointmentDispatchTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected int $nominationId;

    /** An appointment against a nomination, optionally already sent. */
    protected function appointment(int $hanniExaminerId, ?string $sentAt): AppointmentExaminer
    {
        $student = User::firstOrCreate(
            ['email' => 'cand@test.my'],
            ['name' => 'Ahmad Danial', 'password' => 'password',
                'role' => Role::STUDENT, 'matric_no' => '22001001'],
        );

        $cgs = User::firstOrCreate(
            ['email' => 'syahmi@test.my'],
            ['name' => 'M Syahmi Ifwat M Jafri', 'password' => 'password', 'role' => Role::NON_EXEC_CGS],
        );

        // One nomination for the whole test, created on first use.
        $this->nominationId ??= Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $cgs->id,
            'module_type' => 'examiner_nomination', 'status' => Application::STATUS_APPROVED,
        ])->id;

        $application = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $cgs->id,
            'module_type' => 'appointment_letter', 'status' => Application::STATUS_APPROVED,
        ]);

        AppointmentDetail::create([
            'application_id' => $application->id,
            'nomination_application_id' => $this->nominationId,
            'thesis_title' => 'Introduction to IoT',
        ]);

        return AppointmentExaminer::create([
            'application_id' => $application->id,
            'hani_examiner_id' => $hanniExaminerId,
            'examiner_type' => 'internal',
            'examiner_role' => 'main',
            'examiner_name' => 'Dr. Chandra Segaran',
            'examiner_institution' => 'Computing, UTP',
            'examiner_email' => 'chandra@utp.edu.my',
            'examiner_address' => 'Computing',
            'letter_ref_no' => 'UTP/CGS/001/1',
            'appointed_at' => now(),
            'pack_sent_at' => $sentAt,
        ]);
    }

    public function test_it_returns_the_time_the_pack_was_accepted(): void
    {
        $this->appointment(11, '2026-11-01 09:30:00');

        $when = (new AppointmentDispatch())->packSentAt($this->nominationId, 11);

        $this->assertNotNull($when);
        $this->assertSame('2026-11-01 09:30', $when->format('Y-m-d H:i'));
    }

    /** Nothing accepted yet is not the same as never appointed. */
    public function test_an_unsent_pack_answers_null(): void
    {
        $this->appointment(11, null);

        $this->assertNull((new AppointmentDispatch())->packSentAt($this->nominationId, 11));
    }

    public function test_an_examiner_with_no_appointment_at_all_answers_null(): void
    {
        $this->appointment(11, '2026-11-01 09:30:00');

        $this->assertNull((new AppointmentDispatch())->packSentAt($this->nominationId, 99));
        $this->assertNull((new AppointmentDispatch())->packSentAt(999_999, 11));
    }

    /**
     * One nomination can produce several appointments -- a resend, or a
     * letter issued later for a promoted backup. The question is "has this
     * reached them", so the most recent yes is the answer.
     */
    public function test_the_latest_send_wins(): void
    {
        $this->appointment(11, '2026-11-01 09:30:00');
        $this->appointment(11, '2026-11-03 14:05:00');
        $this->appointment(11, null);

        $when = (new AppointmentDispatch())->packSentAt($this->nominationId, 11);

        $this->assertSame('2026-11-03 14:05', $when->format('Y-m-d H:i'));
    }

    /**
     * The keys are the chain's. This module's own application id happens to
     * be a number too, and asking with it must not accidentally work.
     */
    public function test_this_modules_own_application_id_is_not_the_key(): void
    {
        $row = $this->appointment(11, '2026-11-01 09:30:00');

        $this->assertNotSame($this->nominationId, $row->application_id,
            'The two ids must differ, or this test proves nothing.');

        $this->assertNull((new AppointmentDispatch())->packSentAt($row->application_id, 11));
    }

    /** Nor is the appointment row's own id the examiner key. */
    public function test_the_appointment_row_id_is_not_the_examiner_key(): void
    {
        $row = $this->appointment(11, '2026-11-01 09:30:00');

        if ($row->id !== 11) {
            $this->assertNull((new AppointmentDispatch())->packSentAt($this->nominationId, $row->id));
        }

        $this->assertNotNull((new AppointmentDispatch())->packSentAt($this->nominationId, 11));
    }

    /**
     * The binding waits for Core. Until the interface exists nothing is
     * bound, and binding a class to a missing interface would be fatal on
     * every request in the application, not just this module's.
     */
    public function test_the_binding_is_skipped_while_the_interface_is_absent(): void
    {
        $this->assertSame(
            'App\Modules\Core\Contracts\SuppliesAppointmentDispatch',
            AppointmentDispatch::CONTRACT,
        );

        if (interface_exists(AppointmentDispatch::CONTRACT)) {
            $this->assertTrue($this->app->bound(AppointmentDispatch::CONTRACT),
                'Once Core has the interface, the provider should bind it.');

            return;
        }

        $this->assertFalse($this->app->bound(AppointmentDispatch::CONTRACT));

        // And registering again changes nothing, rather than throwing.
        (new ModuleProvider($this->app))->register();

        $this->assertFalse($this->app->bound(AppointmentDispatch::CONTRACT));
    }
}
