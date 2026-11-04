<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\HardboundExaminerSignature;
use App\Modules\Jason\Support\AppointmentSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Ten applications through each of the three chains, end to end.
 *
 * The other files in here test one rule at a time. This one asks a
 * different question: put a realistic batch through each module -- some
 * straight through, some returned, some resubmitted, some refused -- and
 * check every one lands where it should. It is the run to look at before
 * a demo, because it is the only test that exercises a chain at volume.
 */
class TenApplicationsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // An env var in the container wins over phpunit.xml, so queued mail
        // would otherwise be handed to the dev worker instead of sent here.
        config(['queue.default' => 'sync']);
    }

    /** Reported at the end of each walkthrough, so a run reads as a table. */
    protected array $log = [];

    protected function note(string $ref, string $outcome): void
    {
        $this->log[] = str_pad($ref, 22).$outcome;
    }

    protected function report(string $title): void
    {
        fwrite(STDERR, "\n  ".$title."\n  ".str_repeat('-', 58)."\n");

        foreach ($this->log as $line) {
            fwrite(STDERR, '  '.$line."\n");
        }

        fwrite(STDERR, "\n");
        $this->log = [];
    }

    protected function signatureFor(User $user): void
    {
        $this->actingAs($user)
            ->post(route('hardbound.signature.store'), [
                'signature' => UploadedFile::fake()->image('signature.png', 300, 100),
            ])
            ->assertSessionHasNoErrors();
    }

    protected function candidate(int $n): User
    {
        return $this->student('2200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), "cand{$n}@test.my");
    }

    /* =================================================================
     | 1. Hardbound Submission: student, supervisor, examiner, chair, CGS
     |==================================================================*/

    public function test_ten_hardbound_submissions_walk_the_whole_chain(): void
    {
        Storage::fake('local');
        Mail::fake();

        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $cgs = $this->cgs();
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $examinerId = DB::table('examiners')->insertGetId([
            'name' => 'Dr. Internal Examiner', 'email' => 'internal@test.my',
            'department' => 'Computing', 'faculty' => 'FSMC', 'type' => 'internal',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $approved = 0;
        $returned = 0;

        for ($n = 1; $n <= 10; $n++) {
            $student = $this->candidate($n);
            $this->signatureFor($student);

            $this->actingAs($student)
                ->post(route('hardbound.store'), [
                    'internal_examiner_id' => $examinerId,
                    'thesis_title' => "Thesis number {$n}",
                    'programme' => 'PhD Full-Time',
                    'supervisor_name' => 'Dr. Aisyah Rahman',
                    'country' => 'MYS', 'intake' => 'SEP23',
                    'passport_no' => 'A1234567'.$n,
                    'date_of_birth' => '2000-04-17',
                    'viva_date' => now()->subMonth()->toDateString(),
                ])
                ->assertSessionHasNoErrors();

            $application = Application::where('module_type', 'hardbound_submission')
                ->where('student_id', $student->id)->firstOrFail();

            $ref = "#{$application->id} candidate {$n}";

            // Case 7: the supervisor sends it back, the candidate corrects it.
            if ($n === 7) {
                $this->actingAs($supervisor)->post(route('hardbound.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'Chapter 4 corrections are missing.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);

                $this->actingAs($student)
                    ->post(route('hardbound.resubmit', $application), [
                        'internal_examiner_id' => $examinerId,
                        'thesis_title' => "Thesis number {$n} (corrected)",
                        'programme' => 'PhD Full-Time',
                        'supervisor_name' => 'Dr. Aisyah Rahman',
                        'country' => 'MYS', 'intake' => 'SEP23',
                        'passport_no' => 'A1234567'.$n,
                        'date_of_birth' => '2000-04-17',
                        'viva_date' => now()->subMonth()->toDateString(),
                        'response_to_comments' => 'Chapter 4 corrections are now in.',
                    ])
                    ->assertSessionHasNoErrors();

                // A resubmission is a NEW application that replaces the
                // returned one, so the returned row keeps its own history
                // rather than being reopened. Follow the replacement.
                $replacement = Application::where('module_type', 'hardbound_submission')
                    ->where('student_id', $student->id)
                    ->where('id', '>', $application->id)
                    ->orderByDesc('id')
                    ->firstOrFail();

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->assertSame('supervisor', $replacement->current_stage);

                $application = $replacement;
                $ref = "#{$application->id} candidate {$n}";
            }

            $this->actingAs($supervisor)
                ->post(route('hardbound.decide', $application), ['decision' => 'approve']);

            $this->assertSame('chair', $application->fresh()->current_stage,
                "Candidate {$n} did not pass the Supervisor.");

            // The gate: the chairman cannot certify before the examiner signs.
            $this->actingAs($chair)
                ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
                ->assertSessionHas('error');

            $this->assertSame('chair', $application->fresh()->current_stage,
                "Candidate {$n} passed the chair before the examiner signed.");

            $this->post(URL::temporarySignedRoute('hardbound.examiner.sign.store', now()->addDay(), [
                'application' => $application->id, 'slot' => 'internal',
            ]), ['signature' => UploadedFile::fake()->image('examiner.png', 300, 100)])
                ->assertSessionHasNoErrors();

            $this->assertNotNull(HardboundExaminerSignature::forApplication($application->id));

            // Case 8: the chairman sends it back and it stays there.
            if ($n === 8) {
                $this->actingAs($chair)->post(route('hardbound.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'Spelling throughout chapter 2.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->note($ref, 'returned by the Chairman, waiting on the candidate');
                $returned++;

                continue;
            }

            $this->actingAs($chair)->post(route('hardbound.decide', $application), ['decision' => 'approve']);
            $this->assertSame('cgs_review', $application->fresh()->current_stage);

            // Case 9: CGS sends it back.
            if ($n === 9) {
                $this->actingAs($cgs)->post(route('hardbound.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'The wrong thesis file was filed.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->note($ref, 'returned by CGS, waiting on the candidate');
                $returned++;

                continue;
            }

            $this->actingAs($cgs)->post(route('hardbound.decide', $application), ['decision' => 'approve']);
            $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

            $this->note($ref, $n === 7
                ? 'returned by the Supervisor, resubmitted, approved'
                : 'approved (supervisor, examiner, chairman, CGS)');
            $approved++;
        }

        $this->report('Hardbound Submission: ten candidates');

        $this->assertSame(8, $approved);
        $this->assertSame(2, $returned);
        // Eleven rows for ten candidates: candidate 7's resubmission is a
        // new application, and the returned one it replaces stays on record.
        $this->assertSame(11, Application::where('module_type', 'hardbound_submission')->count());
    }

    /* =================================================================
     | 2. Appeal Hardbound: the extension memo
     |==================================================================*/

    public function test_ten_extension_memos_walk_the_whole_chain(): void
    {
        Storage::fake('local');
        Mail::fake();

        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $cgs = $this->cgs();

        // Endorsing stamps the endorser's signature into the memo.
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $received = 0;
        $returned = 0;

        for ($n = 1; $n <= 10; $n++) {
            $student = $this->candidate($n);
            $this->signatureFor($student);

            $this->actingAs($student)
                ->post(route('hardbound-appeal.store'), [
                    'reason' => "Laboratory access was withdrawn for candidate {$n}.",
                    'original_deadline' => now()->addWeek()->toDateString(),
                    'requested_until' => now()->addMonths(3)->toDateString(),
                ])
                ->assertSessionHasNoErrors();

            $application = Application::where('module_type', 'hardbound_appeal')
                ->where('student_id', $student->id)->firstOrFail();

            $ref = "#{$application->id} candidate {$n}";

            // One memo at a time: a second while the first is open is refused.
            if ($n === 10) {
                $this->actingAs($student)
                    ->post(route('hardbound-appeal.store'), [
                        'reason' => 'A second memo for the same deadline.',
                        'requested_until' => now()->addMonths(4)->toDateString(),
                    ])
                    ->assertSessionHas('error');

                $this->assertSame(1, Application::where('module_type', 'hardbound_appeal')
                    ->where('student_id', $student->id)->count());
            }

            // The candidate can read their own memo at any point.
            $this->actingAs($student)->get(route('hardbound-appeal.read', $application))->assertOk();

            // Case 8: the supervisor sends it back.
            if ($n === 8) {
                $this->actingAs($supervisor)->post(route('hardbound-appeal.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'Give the date your fieldwork ends.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->note($ref, 'returned by the Supervisor');
                $returned++;

                continue;
            }

            $this->actingAs($supervisor)->post(route('hardbound-appeal.decide', $application), ['decision' => 'approve']);
            $this->assertSame('chair', $application->fresh()->current_stage);

            // Case 9: the HOD sends it back.
            if ($n === 9) {
                $this->actingAs($chair)->post(route('hardbound-appeal.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'Three months is beyond what the department can endorse.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->note($ref, 'returned by the HOD');
                $returned++;

                continue;
            }

            $this->actingAs($chair)->post(route('hardbound-appeal.decide', $application), ['decision' => 'approve']);
            $this->assertSame('cgs_review', $application->fresh()->current_stage);

            $this->actingAs($cgs)->post(route('hardbound-appeal.decide', $application), ['decision' => 'approve']);
            $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

            $this->note($ref, $n === 10
                ? 'received by CGS (second memo correctly refused)'
                : 'received by CGS, candidate told to expect 7 to 14 days');
            $received++;
        }

        $this->report('Appeal Hardbound Submission: ten extension memos');

        $this->assertSame(8, $received);
        $this->assertSame(2, $returned);
    }

    /* =================================================================
     | 3. Appointment Letters: import, prepare, Dean
     |==================================================================*/

    public function test_ten_appointment_packs_walk_the_whole_chain(): void
    {
        Storage::fake('local');
        Mail::fake();

        $cgs = $this->user(Role::NON_EXEC_CGS, ['name' => 'M Syahmi Ifwat M Jafri', 'email' => 'syahmi@test.my']);
        $dean = $this->dean();

        foreach ([
            ['Dr. Chandra Segaran', 'internal', 'chandra@utp.edu.my', null],
            ['Prof. Dr. Rosli Hamid', 'internal', 'rosli@utp.edu.my', null],
            ['Assoc Prof Dr Azimah Binti Hussin', 'external', 'azimah@ukm.edu.my', 'Universiti Kebangsaan Malaysia'],
            ['Professor Ir Ts Dr Muzamir Bin Isa', 'external', 'muzamir@unimap.edu.my', 'Universiti Malaysia Perlis'],
        ] as [$name, $type, $email, $institution]) {
            DB::table('examiners')->insert([
                'name' => $name, 'email' => $email, 'department' => 'Computing',
                'faculty' => 'FSMC', 'institution' => $institution, 'type' => $type,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = [];

        for ($n = 1; $n <= 10; $n++) {
            $matric = '2200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $this->candidate($n);

            $rows[] = [
                (string) $n, 'FSMC', 'Computing', 'Ahmad Danial', $matric, 'MSc Full-Time',
                "Introduction to IoT, part {$n}", 'Dr. Aisyah Rahman',
                'Dr. Chandra Segaran', 'Civil & Environmental Engineering',
                'Prof. Dr. Rosli Hamid',
                'Assoc Prof Dr Azimah Binti Hussin', 'Universiti Kebangsaan Malaysia',
                'Professor Ir Ts Dr Muzamir Bin Isa',
                '-', 'Approved', '2026-09-14',
            ];
        }

        $csv = implode(',', AppointmentSheet::TEMPLATE_HEADINGS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $row))."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($cgs)
            ->post(route('appointment-letter.import.store'), [
                'sheet' => new UploadedFile($path, 'examiner-list.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appointment-letter.queue'));

        $applications = Application::where('module_type', 'appointment_letter')->orderBy('id')->get();

        $this->assertCount(10, $applications, 'One row should open one appointment.');

        $approved = 0;
        $returned = 0;
        $n = 0;

        foreach ($applications as $application) {
            $n++;
            $ref = "#{$application->id} candidate {$n}";

            $this->assertSame('cgs_prep', $application->current_stage);

            $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();
            $examiners = AppointmentExaminer::where('application_id', $application->id)->get();

            $this->assertCount(4, $examiners, "Candidate {$n} should carry four examiners.");

            $payload = [
                'candidate_degree' => 'Master of Science',
                'candidate_programme' => 'MSc Full-Time',
                'supervisor_name' => 'Dr. Aisyah Rahman',
                'thesis_title' => $detail->thesis_title ?: "Introduction to IoT, part {$n}",
                'examiners' => $examiners->map(fn ($e, $i) => [
                    'id' => $e->id,
                    'examiner_type' => $e->examiner_type,
                    'examiner_address' => 'Bandar Seri Iskandar, 32610 Perak',
                    'letter_ref_no' => sprintf('UTP/CGS/%03d/%d', $n, $i + 1),
                ])->all(),
                'remarks' => null,
            ];

            // The Dean cannot approve before a signature is on file, so the
            // first pack tries it without one.
            if ($n === 1) {
                $this->actingAs($cgs)->post(route('appointment-letter.prepare.store', $application), $payload)
                    ->assertSessionHasNoErrors();

                $this->assertSame('dean', $application->fresh()->current_stage);

                $this->actingAs($dean)
                    ->post(route('appointment-letter.decide', $application), ['decision' => 'approve'])
                    ->assertRedirect(route('hardbound.signature'));

                $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);

                $this->signatureFor($dean);
            } else {
                $this->actingAs($cgs)->post(route('appointment-letter.prepare.store', $application), $payload)
                    ->assertSessionHasNoErrors();

                $this->assertSame('dean', $application->fresh()->current_stage);
            }

            $letters = $application->documents()->where('doc_type', 'Appointment Letter')->count();
            $reports = $application->documents()->where('doc_type', 'Thesis Evaluation Report')->count();

            $this->assertSame(4, $letters, "Candidate {$n} should have four letters.");
            $this->assertSame(4, $reports, "Candidate {$n} should have four evaluation reports.");

            // Case 10: the Dean sends the pack back to CGS.
            if ($n === 10) {
                $this->actingAs($dean)->post(route('appointment-letter.decide', $application), [
                    'decision' => 'reject', 'remarks' => 'The external examiner is at the candidate\'s own institution.',
                ]);

                $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
                $this->note($ref, 'returned by the Dean, 8 documents prepared');
                $returned++;

                continue;
            }

            $this->actingAs($dean)
                ->post(route('appointment-letter.decide', $application), ['decision' => 'approve']);

            $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

            $this->note($ref, $n === 1
                ? 'approved, 8 documents (Dean refused once with no signature)'
                : 'approved, 8 documents signed by the Dean');
            $approved++;
        }

        $this->report('Appointment Letters: ten packs');

        $this->assertSame(9, $approved);
        $this->assertSame(1, $returned);
    }
}
