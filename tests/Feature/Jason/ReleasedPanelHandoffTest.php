<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Support\ReleasedPanels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The hand-off from examiner nomination, and what happens when a panel
 * changes after its pack has gone out.
 *
 * The contract this reads is proposed but not yet in Core, so the first
 * thing these tests establish is that its absence is not an error: the
 * page works, and the spreadsheet is simply still the way in. The rest
 * bind a fake under the contract's own name to prove the live path.
 *
 * Nothing here imports from another member's folder. The fake implements
 * the shape agreed in writing, which is the point: if either side drifts
 * from it, this fails rather than the demo.
 */
class ReleasedPanelHandoffTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    protected function cgs(): User
    {
        return User::firstOrCreate(
            ['email' => 'syahmi@test.my'],
            ['name' => 'M Syahmi Ifwat M Jafri', 'password' => 'password', 'role' => Role::NON_EXEC_CGS],
        );
    }

    /** The same candidate each time: several of these tests need them twice. */
    protected function candidate(): User
    {
        return User::firstOrCreate(
            ['email' => 'cand@test.my'],
            ['name' => 'Ahmad Danial', 'password' => 'password',
                'role' => Role::STUDENT, 'matric_no' => '22001001'],
        );
    }

    protected function examiner(string $name, string $type, ?string $email = null): int
    {
        return DB::table('examiners')->insertGetId([
            'name' => $name,
            'email' => $email ?? str_replace(' ', '.', strtolower($name)).'@test.my',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'institution' => $type === 'external' ? 'Universiti Malaya' : null,
            'type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * An appointment already sent for one nomination, naming one examiner.
     *
     * This is the state a promotion happens against: the pack went out, and
     * then the panel changed underneath it.
     */
    protected function alreadySent(int $nominationId, int $examinerId, string $name): Application
    {
        $student = $this->candidate();

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $this->cgs()->id,
            'module_type' => 'appointment_letter',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'dean',
        ]);

        AppointmentDetail::create([
            'application_id' => $application->id,
            'nomination_application_id' => $nominationId,
            'candidate_degree' => 'Master of Science',
            'candidate_programme' => 'Computing',
            'supervisor_name' => 'Dr. Aisyah Rahman',
            'thesis_title' => 'Introduction to IoT',
        ]);

        AppointmentExaminer::create([
            'application_id' => $application->id,
            'hani_examiner_id' => $examinerId,
            'examiner_type' => 'internal',
            'examiner_role' => 'main',
            'examiner_name' => $name,
            'examiner_institution' => 'Computing, UTP',
            'examiner_email' => 'held@test.my',
            'examiner_address' => 'Computing',
            'letter_ref_no' => 'UTP/CGS/001/1',
            'appointed_at' => now(),
            'pack_sent_at' => now(),
        ]);

        return $application;
    }

    /** A stand-in for the nomination chain, in the agreed shape. */
    protected function bindFakeChain(array $panels): void
    {
        $this->app->bind(ReleasedPanels::CONTRACT, fn () => new class($panels)
        {
            public function __construct(protected array $panels) {}

            public function releasedPanels(?\DateTimeInterface $since = null): array
            {
                return $this->panels;
            }

            public function panelForStudent(int $studentId): ?array
            {
                return $this->panels[0] ?? null;
            }
        });
    }

    /** @return array<string, mixed> */
    protected function panel(int $nominationId, array $internal, array $external): array
    {
        return [
            'nomination_id' => $nominationId,
            'application_id' => $nominationId,
            'released_at' => now(),
            'student' => ['id' => 1, 'name' => 'Ahmad Danial', 'matric_id' => '22001001',
                'programme' => 'Computing', 'degree' => 'MSc', 'department' => 'Computing'],
            'supervisor' => ['name' => 'Dr. Aisyah Rahman', 'email' => 'sup@test.my'],
            'thesis_title' => 'Introduction to IoT',
            'examiners' => [
                'internal_main' => $internal,
                'internal_backup' => null,
                'external_main' => $external,
                'external_backup' => null,
            ],
        ];
    }

    /* ---------------- the contract being absent ---------------- */

    /**
     * The contract is not in Core yet. Nothing may break because of that:
     * no fatal from a missing interface, no error on the page, and the
     * spreadsheet still the way in.
     */
    public function test_the_page_works_and_falls_back_when_the_handoff_is_not_bound(): void
    {
        $this->assertFalse(ReleasedPanels::available(),
            'Nothing should be bound to the contract in a plain test.');

        $this->assertSame([], ReleasedPanels::all());

        $this->actingAs($this->cgs())
            ->get(route('appointment-letter.import'))
            ->assertOk()
            ->assertSee('not switched on yet')
            ->assertSee('Import Examiner List');
    }

    /**
     * The contract is named as a string and never imported, so this module
     * loads and runs whether or not Core has the file. Asking for it by
     * name is the whole mechanism.
     */
    public function test_the_contract_is_named_as_a_string_not_imported(): void
    {
        $this->assertSame('App\Modules\Core\Contracts\SuppliesReleasedExaminers', ReleasedPanels::CONTRACT);

        // Whether Core has the file yet or not, nothing is bound by default
        // and nothing here fails because of it.
        $this->assertFalse(ReleasedPanels::available());
        $this->assertSame([], ReleasedPanels::all());
    }

    /* ---------------- the contract being live ---------------- */

    public function test_released_panels_are_listed_when_the_chain_is_bound(): void
    {
        $this->bindFakeChain([
            $this->panel(80,
                ['id' => 11, 'name' => 'Dr. Chandra Segaran', 'email' => 'chandra@utp.edu.my',
                    'email_problem' => null, 'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $this->assertTrue(ReleasedPanels::available());

        $panels = ReleasedPanels::all();

        $this->assertCount(1, $panels);
        $this->assertSame(80, $panels[0]['nomination_application_id']);

        // The two mains only, with the chain's own examiner ids kept.
        $this->assertCount(2, $panels[0]['examiners']);
        $this->assertEqualsCanonicalizing(
            [11, 12],
            array_column($panels[0]['examiners'], 'hani_examiner_id'),
        );

        $this->actingAs($this->cgs())
            ->get(route('appointment-letter.import'))
            ->assertOk()
            ->assertSee('Ready for appointment letters')
            ->assertSee('Dr. Chandra Segaran')
            ->assertDontSee('not switched on yet');
    }

    /** An address the chain says is unusable arrives with its own sentence. */
    public function test_the_chains_own_email_problem_is_carried_through(): void
    {
        $this->bindFakeChain([
            $this->panel(80,
                ['id' => 11, 'name' => 'Dr. Chandra Segaran', 'email' => null,
                    'email_problem' => 'Reported as not working on 3 November.',
                    'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $seats = collect(ReleasedPanels::all()[0]['examiners'])->keyBy('hani_examiner_id');

        $this->assertSame('Reported as not working on 3 November.', $seats[11]['email_problem']);
        $this->assertNull($seats[12]['email_problem']);
    }

    /* ---------------- a promoted backup ---------------- */

    /**
     * A main declined after the pack went out. The chain shows the promoted
     * backup in the main seat; this module has written to the person who
     * was there before. The difference is the signal.
     */
    public function test_a_promoted_backup_is_listed_as_needing_a_letter(): void
    {
        $this->alreadySent(80, 11, 'Dr. Chandra Segaran');

        $this->bindFakeChain([
            $this->panel(80,
                // 11 declined; 13 was the backup and now holds the seat.
                ['id' => 13, 'name' => 'Dr. Tan Boon Keat', 'email' => 'tan@utp.edu.my',
                    'email_problem' => null, 'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $needed = ReleasedPanels::lettersNeeded();

        // 13 and 12 both lack a letter; 11 has one and must never reappear.
        $this->assertEqualsCanonicalizing([13, 12], array_column($needed, 'hani_examiner_id'));
        $this->assertNotContains(11, array_column($needed, 'hani_examiner_id'));
    }

    /** Preparing one opens its own appointment, which the Dean still signs. */
    public function test_preparing_a_promoted_examiner_opens_its_own_appointment(): void
    {
        $sent = $this->alreadySent(80, 11, 'Dr. Chandra Segaran');

        $this->bindFakeChain([
            $this->panel(80,
                ['id' => 13, 'name' => 'Dr. Tan Boon Keat', 'email' => 'tan@utp.edu.my',
                    'email_problem' => null, 'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.promoted'), [
                'nomination_application_id' => 80,
                'hani_examiner_id' => 13,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appointment-letter.queue'));

        $opened = Application::where('module_type', 'appointment_letter')
            ->where('id', '!=', $sent->id)->firstOrFail();

        // Through the chain from the start: the Dean signs every letter.
        $this->assertSame('cgs_prep', $opened->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $opened->status);

        $panel = AppointmentExaminer::where('application_id', $opened->id)->get();

        $this->assertCount(1, $panel, 'Only the promoted examiner belongs on this appointment.');
        $this->assertSame(13, (int) $panel->first()->hani_examiner_id);
        $this->assertSame('Dr. Tan Boon Keat', $panel->first()->examiner_name);

        // The nomination is carried over, so a later promotion sees this one.
        $this->assertSame(80, (int) AppointmentDetail::where('application_id', $opened->id)
            ->firstOrFail()->nomination_application_id);
    }

    /** Someone who already has a letter can never be given a second one. */
    public function test_an_examiner_who_already_has_a_letter_is_refused(): void
    {
        $this->alreadySent(80, 11, 'Dr. Chandra Segaran');

        $this->bindFakeChain([
            $this->panel(80,
                ['id' => 11, 'name' => 'Dr. Chandra Segaran', 'email' => 'chandra@utp.edu.my',
                    'email_problem' => null, 'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $before = Application::where('module_type', 'appointment_letter')->count();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.promoted'), [
                'nomination_application_id' => 80,
                'hani_examiner_id' => 11,
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, Application::where('module_type', 'appointment_letter')->count());
    }

    /**
     * A first import is not a promotion. A nomination this module has never
     * written to belongs on the import screen, not on this list.
     */
    public function test_a_nomination_with_no_letters_at_all_is_not_listed(): void
    {
        $this->bindFakeChain([
            $this->panel(99,
                ['id' => 11, 'name' => 'Dr. Chandra Segaran', 'email' => 'chandra@utp.edu.my',
                    'email_problem' => null, 'department' => 'Computing', 'faculty' => 'FSMC'],
                ['id' => 12, 'name' => 'Prof. Madya Dr. Nabila Yusof', 'email' => 'nabila@um.edu.my',
                    'email_problem' => null, 'institution' => 'Universiti Malaya', 'department' => 'Computing'],
            ),
        ]);

        $this->assertSame([], ReleasedPanels::lettersNeeded());
    }

    /**
     * Without the contract, the current panel is still read -- straight
     * from the nomination row, which always shows the panel as it stands.
     * So a promotion is spotted today, before the hand-off is switched on.
     */
    public function test_a_promotion_is_still_spotted_without_the_contract(): void
    {
        $student = $this->candidate();

        // The nomination's own application: examiner_nominations has a
        // foreign key to it, so it has to be a real row, and its id is the
        // number everything else refers to.
        $nomination = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $this->cgs()->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_APPROVED,
        ]);

        $declined = $this->examiner('Dr. Chandra Segaran', 'internal');
        $promoted = $this->examiner('Dr. Tan Boon Keat', 'internal');
        $external = $this->examiner('Prof. Madya Dr. Nabila Yusof', 'external');

        $this->alreadySent($nomination->id, $declined, 'Dr. Chandra Segaran');

        // The chain's row, showing the CURRENT panel: the backup has
        // already been promoted into the main seat.
        DB::table('examiner_nominations')->insert([
            'application_id' => $nomination->id,
            'internal_main_id' => $promoted,
            'external_main_id' => $external,
            'thesis_title' => 'Introduction to IoT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(ReleasedPanels::available());

        $this->assertEqualsCanonicalizing(
            [$promoted, $external],
            array_column(ReleasedPanels::lettersNeeded(), 'hani_examiner_id'),
        );
    }

    /** Only the CGS officer who sends the letters may open one. */
    public function test_only_non_executive_cgs_may_prepare_a_promoted_letter(): void
    {
        $this->actingAs($this->chair())
            ->post(route('appointment-letter.promoted'), [
                'nomination_application_id' => 80, 'hani_examiner_id' => 13,
            ])
            ->assertForbidden();
    }
}
