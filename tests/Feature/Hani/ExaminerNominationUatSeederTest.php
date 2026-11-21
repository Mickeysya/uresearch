<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Database\Seeders\ExaminerNominationUatSeeder;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Hani\Concerns\FakesAPrivateLocalDisk;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** ExaminerNominationUatSeeder: the lecturer UAT's starting point, and putting it back. */
class ExaminerNominationUatSeederTest extends TestCase
{
    use FakesAPrivateLocalDisk;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePrivateLocalDisk();
        Notification::fake();

        foreach (['Computing' => 'FSMC', 'Electrical & Electronics Engineering' => 'FOE'] as $name => $faculty) {
            Department::create(['name' => $name, 'faculty' => $faculty]);
        }

        $this->supervisor = $this->user(Role::SUPERVISOR, ['name' => 'Dr. Aisyah Rahman', 'email' => 'supervisor@utp.edu.my', 'department' => 'Computing']);
        $this->ae = $this->user(Role::ACADEMIC_EXEC, ['name' => 'Siti Academic Exec', 'email' => 'ae@utp.edu.my', 'department' => 'Computing']);
        $this->student = $this->user(Role::STUDENT, [
            'name' => 'Nur Farah Adilah', 'email' => 'student2@utp.edu.my', 'matric_no' => '22001002',
            'programme' => 'PhD Part-Time', 'department' => 'Computing', 'supervisor_id' => $this->supervisor->id,
        ]);
    }

    protected function seedUat(): void
    {
        $this->seed(ExaminerNominationUatSeeder::class);
    }

    protected function suggestions(): array
    {
        return $this->actingAs($this->supervisor)
            ->getJson(route('examiner-nomination.suggestions', ['student_id' => $this->student->id]))
            ->assertOk()
            ->json();
    }

    public function test_the_notice_is_verified_and_waiting_for_the_supervisor_to_nominate(): void
    {
        $this->seedUat();

        $notice = Application::where('student_id', $this->student->id)->where('module_type', VivaProgress::NOTICE)->sole();
        $this->assertSame(Application::STATUS_APPROVED, $notice->status);
        $this->assertSame(
            ['sv_endorsement' => $this->supervisor->id, 'notice_received' => $this->ae->id],
            $notice->history()->pluck('approver_id', 'stage_key')->all(),
        );
        $this->assertFalse(Application::where('student_id', $this->student->id)->where('module_type', VivaProgress::NOMINATION)->exists());

        $this->actingAs($this->supervisor)->get(route('viva.supervisees'))
            ->assertOk()->assertSee('Nur Farah Adilah')->assertSee('Not started');
        $this->actingAs($this->supervisor)->get(route('examiner-nomination.create'))
            ->assertOk()->assertSee('Nur Farah Adilah')->assertSee('Prof Dr Rahmat bin Ismail, Computing')->assertSee('(On Gap)');
    }

    public function test_the_form_suggests_matches_with_conflict_and_duplicate_flags(): void
    {
        $this->seedUat();
        $data = $this->suggestions();

        $internal = collect($data['internal'])->keyBy('name');
        $external = collect($data['external'])->keyBy('name');

        foreach (['Dr Tan Mei Xuan', 'Dr Mohd Haikal bin Hashim', 'Assoc Prof Dr Hafizah binti Kamaruddin'] as $name) {
            $this->assertGreaterThanOrEqual(30, $internal[$name]['score'] ?? 0, "{$name} should be a good internal match");
        }
        foreach (['Assoc Prof Dr Gan Wei Jie', 'Prof Dr Ong Siew Ling', 'Dr Kavitha a/p Raman'] as $name) {
            $this->assertGreaterThanOrEqual(30, $external[$name]['score'] ?? 0, "{$name} should be a good external match");
        }

        $rules = fn ($row) => array_column($row['flags'], 'rule');
        $this->assertContains('coauthor', $rules($internal['Dr Tan Mei Xuan']));
        $this->assertContains('clash', $rules($external['Assoc Prof Dr Gan Wei Jie']));
        $this->assertContains('utp_appointment', $rules($external['Dr Kavitha a/p Raman']));

        // On the 90-day gap: never suggested.
        $this->assertArrayNotHasKey('Prof Dr Rahmat bin Ismail', $internal->all());
        $this->assertFalse(Examiner::where('email', 'rahmat.ismail@example.com')->sole()->isEligible());
    }

    public function test_the_ae_sees_the_softbound_with_passed_and_warned_checks(): void
    {
        $this->seedUat();

        $softbound = Application::where('student_id', $this->student->id)->where('module_type', VivaProgress::SOFTBOUND)->sole();
        $this->assertSame(Application::STATUS_PENDING, $softbound->status);
        $this->assertSame('softbound_check', $softbound->current_stage);

        $check = VivaSoftboundCheck::where('application_id', $softbound->id)->sole();
        $this->assertSame(VivaSoftboundCheck::DONE, $check->status);
        $statuses = collect($check->results)->pluck('status', 'key');
        $this->assertSame('warning', $statuses['similarity_read']);
        $this->assertSame('warning', $statuses['report_age']);
        $this->assertSame('pass', $statuses['thesis_title']);
        $this->assertSame('pass', $statuses['word_count']);

        $this->actingAs($this->ae)->get(route('viva.softbound.queue'))
            ->assertOk()->assertSee('Nur Farah Adilah')->assertSee('System checks')->assertSee('Older than 30 days');
    }

    public function test_running_it_again_puts_back_the_same_state_and_leaves_other_records_alone(): void
    {
        // Not Hani's: must survive every run.
        $travel = Application::create(['student_id' => $this->student->id, 'module_type' => 'travel', 'status' => Application::STATUS_DRAFT]);

        $this->seedUat();
        $firstNotice = Application::where('student_id', $this->student->id)->where('module_type', VivaProgress::NOTICE)->value('id');
        $examiners = Examiner::count();
        $users = User::count();

        // What the UAT does: the supervisor files a nomination; and a notification points at it.
        $filed = Application::create([
            'student_id' => $this->student->id, 'submitted_by_id' => $this->supervisor->id,
            'module_type' => VivaProgress::NOMINATION, 'status' => Application::STATUS_PENDING, 'current_stage' => 'academic_exec',
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $this->ae->id,
            'data' => json_encode(['application_id' => $filed->id, 'title' => 'Filed']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Examiner::where('email', 'tan.meixuan@example.com')->update(['assigned_until' => now()->addMonths(6)]);

        $this->seedUat();

        $this->assertModelExists($travel);
        $this->assertModelMissing($filed);
        $this->assertFalse(Application::whereKey($firstNotice)->exists());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame($examiners, Examiner::count());
        $this->assertSame($users, User::count());
        $this->assertTrue(Examiner::where('email', 'tan.meixuan@example.com')->sole()->isEligible());
        $this->assertEquals(
            [VivaProgress::NOTICE => 1, VivaProgress::SOFTBOUND => 1, 'travel' => 1],
            Application::where('student_id', $this->student->id)->get()->countBy('module_type')->all(),
        );
        $this->assertCount(3, collect($this->suggestions()['internal'])->filter(fn ($r) => $r['score'] >= 30));
    }
}
