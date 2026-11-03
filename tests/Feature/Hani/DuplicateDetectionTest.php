<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerDuplicateDismissal;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Support\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Support\DuplicateDetector: in the pool, and across departments at cgs_compile. */
class DuplicateDetectionTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function external(string $name, string $email): Examiner
    {
        return Examiner::create([
            'name' => $name, 'email' => $email, 'department' => 'Civil & Environmental Engineering',
            'type' => Examiner::TYPE_EXTERNAL, 'institution' => 'Universiti Teknologi Malaysia', 'is_active' => true,
        ]);
    }

    public function test_the_pool_flags_a_likely_duplicate_until_it_is_dismissed(): void
    {
        $a = $this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org');
        $b = $this->external('Hassan Omar, PhD', 'h.omar@example.org');
        $this->external('Prof Dr Sarah Jane Whitmore', 's.whitmore@example.org');

        $senior = $this->seniorExec();

        $this->actingAs($senior)->get(route('examiner-admin.index'))
            ->assertOk()
            ->assertSee('Possible duplicate of Hassan Omar, PhD (100% similar)')
            ->assertSee('Possible duplicate of Prof Dr Hassan bin Omar (100% similar)')
            ->assertDontSee('Possible duplicate of Prof Dr Sarah Jane Whitmore');

        $this->actingAs($senior)
            ->post(route('examiner-admin.dismiss-duplicate'), ['examiner_id' => $b->id, 'other_id' => $a->id])
            ->assertSessionHas('status');

        // Stored lowest id first, so it holds whichever way round it was clicked.
        $this->assertDatabaseHas('examiner_duplicate_dismissals', ['examiner_a_id' => $a->id, 'examiner_b_id' => $b->id]);

        $this->actingAs($senior)->get(route('examiner-admin.index'))->assertDontSee('Possible duplicate of');
        $this->assertSame([], app(DuplicateDetector::class)->pairs(Examiner::all()));
    }

    public function test_internal_and_external_records_are_never_paired(): void
    {
        $this->external('Dr Hassan Omar', 'one@example.org');
        Examiner::create(['name' => 'Dr Hassan Omar', 'email' => 'two@example.com', 'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true]);

        $this->assertSame([], app(DuplicateDetector::class)->pairs(Examiner::all()));
    }

    public function test_only_the_senior_executive_can_dismiss(): void
    {
        $a = $this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org');
        $b = $this->external('Hassan Omar, PhD', 'h.omar@example.org');

        $this->actingAs($this->cgs())
            ->post(route('examiner-admin.dismiss-duplicate'), ['examiner_id' => $a->id, 'other_id' => $b->id])
            ->assertForbidden();

        $this->assertSame(0, ExaminerDuplicateDismissal::count());
    }

    public function test_compilation_flags_two_records_of_one_person_on_different_departments_lists(): void
    {
        $a = $this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org');
        $b = $this->external('Hassan Omar, PhD', 'h.omar@example.org');

        $applications = [];

        foreach ([['Computing', $a], ['Civil & Environmental Engineering', $b]] as $i => [$department, $examiner]) {
            $supervisor = $this->supervisor("sup{$i}@test.my");
            $student = $this->student("2200000{$i}", "s{$i}@test.my", ['supervisor_id' => $supervisor->id, 'department' => $department, 'name' => "Student {$i}"]);
            $internal = Examiner::create(['name' => "Dr Internal {$i}", 'email' => "int{$i}@example.com", 'department' => $department,
                'type' => Examiner::TYPE_INTERNAL, 'is_active' => true]);

            $applications[$i] = Application::create([
                'student_id' => $student->id, 'submitted_by_id' => $supervisor->id, 'module_type' => 'examiner_nomination',
                'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_compile', 'submitted_at' => now(),
            ]);
            ExaminerNomination::create([
                'application_id' => $applications[$i]->id, 'internal_main_id' => $internal->id,
                'external_main_id' => $examiner->id, 'thesis_title' => "Thesis {$i}",
            ]);
        }

        $this->actingAs($this->seniorExec())
            ->get(route('examiner-nomination.queue', ['stage' => 'cgs_compile']))
            ->assertOk()
            ->assertSee('Possible duplicate: &quot;Prof Dr Hassan bin Omar&quot; looks like &quot;Hassan Omar, PhD&quot; on application #'.$applications[1]->id, false)
            ->assertSee('(100% similar)');
    }
}
