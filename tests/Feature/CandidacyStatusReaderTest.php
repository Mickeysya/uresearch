<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\SharedStatusAdapter;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentStatus;
use App\Modules\Chloe\Support\StudentStatusReader;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Stage B: one reader, its lookup order, and the rule that the demo overlay applies to demo students only. */
class CandidacyStatusReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function demo(string $id = '21010001'): DemoStudentProfile
    {
        return DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => 'MSc X', 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);
    }

    protected function overlay(DemoStudentProfile $p, array $values): void
    {
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $p->id] + $values);
    }

    protected function shared(string $studentId, array $row): void
    {
        Schema::create('zz_shared_softbound', function ($t) { $t->string('student_id'); $t->date('submitted_on')->nullable(); $t->string('state')->nullable(); });
        DB::table('zz_shared_softbound')->insert(['student_id' => $studentId] + $row);
        config(['candidacy.status_source_table' => ['table' => 'zz_shared_softbound', 'student_id_column' => 'student_id', 'submitted_at_column' => 'submitted_on', 'status_column' => 'state', 'submitted_values' => ['submitted'], 'completed_values' => ['completed'], 'inactive_values' => ['deferred', 'inactive']]]);
    }

    public function test_a_demo_student_with_no_record_anywhere_is_not_submitted(): void
    {
        $s = StudentStatusReader::for(StudentDirectory::fromDemoProfile($this->demo()));

        $this->assertFalse($s->softboundSubmitted);
        $this->assertFalse($s->completed || $s->inactive || $s->dismissed);
        $this->assertFalse($s->stopsReminders());
        $this->assertFalse($s->isDemoData());
    }

    public function test_the_overlay_answers_for_a_demo_student_and_is_marked_demo_data(): void
    {
        $p = $this->demo();
        $this->overlay($p, ['softbound_submitted_at' => '2026-10-12']);
        $s = StudentStatusReader::for(StudentDirectory::fromDemoProfile($p));

        $this->assertTrue($s->softboundSubmitted);
        $this->assertSame('2026-10-12', $s->softboundDate->toDateString());
        $this->assertSame(StudentStatus::OVERLAY, $s->sources['softbound']);
        $this->assertTrue($s->isDemoData());
        $this->assertTrue($s->stopsReminders());

        $q = $this->demo('21010002');
        $this->overlay($q, ['completed' => true]);
        $this->assertTrue(StudentStatusReader::for(StudentDirectory::fromDemoProfile($q))->completed);
        $r = $this->demo('21010003');
        $this->overlay($r, ['inactive' => true]);
        $this->assertTrue(StudentStatusReader::for(StudentDirectory::fromDemoProfile($r))->inactive);
    }

    public function test_the_overlay_never_applies_to_a_real_student(): void
    {
        $user = User::create(['name' => 'Real', 'email' => 'real@test.my', 'password' => 'password', 'role' => 'student', 'matric_no' => '22001001']);
        StudyCandidacy::create(['student_id' => $user->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);
        // an overlay row for a DEMO student that happens to share the real student's ID must not leak across
        $twin = $this->demo('22001001');
        $this->overlay($twin, ['softbound_submitted_at' => '2026-10-12', 'completed' => true]);

        $s = StudentStatusReader::for(StudentDirectory::fromUser($user));

        $this->assertFalse($s->softboundSubmitted || $s->completed);
        $this->assertFalse($s->isDemoData());
    }

    public function test_the_shared_record_wins_and_the_overlay_never_overrides_it(): void
    {
        $p = $this->demo('21010009');
        $this->overlay($p, ['softbound_submitted_at' => '2026-10-12']);
        $this->shared('21010009', ['submitted_on' => null, 'state' => 'completed']);

        config(['candidacy.status_source' => 'shared_with_demo_fallback']);
        $s = StudentStatusReader::for(StudentDirectory::fromDemoProfile($p));

        $this->assertTrue($s->completed);
        $this->assertFalse($s->softboundSubmitted, 'the overlay did not override the shared record');
        $this->assertSame(StudentStatus::SHARED, $s->sources['completed']);
        $this->assertFalse($s->isDemoData());
    }

    public function test_the_overlay_is_the_fallback_only_when_the_shared_source_has_no_record(): void
    {
        $p = $this->demo('21010010');
        $this->overlay($p, ['softbound_submitted_at' => '2026-10-12']);
        $this->shared('99999999', ['state' => 'submitted', 'submitted_on' => '2026-09-01']); // another student's record

        config(['candidacy.status_source' => 'shared_with_demo_fallback']);
        $this->assertTrue(StudentStatusReader::for(StudentDirectory::fromDemoProfile($p))->isDemoData());

        config(['candidacy.status_source' => 'shared']); // production: the overlay is off
        $s = StudentStatusReader::for(StudentDirectory::fromDemoProfile($p));
        $this->assertFalse($s->softboundSubmitted);
        $this->assertFalse($s->isDemoData());
    }

    public function test_in_demo_mode_the_shared_source_is_not_read_at_all(): void
    {
        $p = $this->demo('21010011');
        $this->shared('21010011', ['state' => 'submitted', 'submitted_on' => '2026-09-01']);

        config(['candidacy.status_source' => 'demo']);

        $this->assertNull(SharedStatusAdapter::lookup('21010011'));
        $this->assertFalse(StudentStatusReader::for(StudentDirectory::fromDemoProfile($p))->softboundSubmitted);
    }

    public function test_the_adapter_is_inactive_until_its_table_is_configured(): void
    {
        config(['candidacy.status_source' => 'shared_with_demo_fallback']);

        $this->assertFalse(SharedStatusAdapter::isConfigured());
        $this->assertNull(SharedStatusAdapter::lookup('21010001'));
    }

    public function test_dismissed_reads_the_candidacy_record(): void
    {
        $user = User::create(['name' => 'Gone', 'email' => 'gone@test.my', 'password' => 'password', 'role' => 'student', 'matric_no' => '22001002']);
        StudyCandidacy::create(['student_id' => $user->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2026-10-01', 'status' => 'dismissed', 'dismissed_at' => '2026-10-20']);

        $s = StudentStatusReader::for(StudentDirectory::fromUser($user));

        $this->assertTrue($s->dismissed);
        $this->assertTrue($s->stopsReminders());
        $this->assertSame(StudentStatus::RECORD, $s->sources['dismissed']);
    }

    public function test_demo_softbound_writes_only_the_overlay_and_refuses_real_students(): void
    {
        $p = $this->demo('21010020');
        $user = User::create(['name' => 'Real', 'email' => 'real2@test.my', 'password' => 'password', 'role' => 'student', 'matric_no' => '22009900']);
        $sharedBefore = Schema::hasTable('zz_shared_softbound');

        $this->artisan('candidacy:demo-softbound', ['studentID' => '21010020', '--date' => '2026-10-05'])->assertSuccessful();
        $this->assertSame('2026-10-05', CandidacyDemoStudentStatus::where('demo_profile_id', $p->id)->first()->softbound_submitted_at->toDateString());

        $this->artisan('candidacy:demo-softbound', ['studentID' => '22009900'])->assertFailed(); // a real student: refused
        $this->assertSame(1, CandidacyDemoStudentStatus::count());
        $this->assertSame($sharedBefore, Schema::hasTable('zz_shared_softbound'));

        $this->artisan('candidacy:demo-softbound', ['studentID' => '21010020', '--completed' => true])->assertSuccessful();
        $row = CandidacyDemoStudentStatus::first();
        $this->assertTrue($row->completed);
        $this->assertNull($row->softbound_submitted_at);

        $this->artisan('candidacy:demo-softbound', ['studentID' => '21010020', '--remove' => true])->assertSuccessful();
        $this->assertSame(0, CandidacyDemoStudentStatus::count());
    }

    public function test_check_status_source_counts_each_source(): void
    {
        $a = $this->demo('21010030');
        $this->demo('21010031');
        $this->overlay($a, ['softbound_submitted_at' => '2026-10-05']);

        $this->artisan('candidacy:check-status-source')->expectsOutputToContain('2 students checked')->assertSuccessful();
    }
}
