<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Stage A: the Dismissal List rule (today is AFTER the expiry date, with every exclusion), the page and its filters. */
class CandidacyDismissalListTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function today(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 07:45:00', 'Asia/Kuala_Lumpur'));
    }

    protected function demo(string $id, string $expiry, string $programme = 'PhD in Information Systems (Full-Time)'): DemoStudentProfile
    {
        return DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => $programme, 'programme_start_date' => '2024-01-01', 'candidacy_expiry_date' => $expiry, 'status' => 'active']);
    }

    /** @return list<string> the student IDs on the list today */
    protected function listed(): array
    {
        return L::candidates()->pluck('studentId')->all();
    }

    protected function code(string $studentId): string
    {
        return L::all()->firstWhere('studentId', $studentId)['code'];
    }

    public function test_the_day_before_the_expiry_the_expiry_day_and_the_day_after(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-10']);
        $id = $this->student->matric_no;

        $this->today('2027-03-09');
        $this->assertNotContains($id, $this->listed(), 'the day before');
        $this->today('2027-03-10');
        $this->assertNotContains($id, $this->listed(), 'the expiry day itself');
        $this->assertSame('not_expired', $this->code($id));
        $this->today('2027-03-11');
        $this->assertContains($id, $this->listed(), 'the day after');
    }

    public function test_a_student_whose_appeal_is_in_review_stays_off_the_list_even_on_and_after_the_expiry_day(): void
    {
        $appeal = $this->submitted(3);
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-10']);
        $id = $this->student->matric_no;

        foreach (['with_supervisor', 'with_chair', 'with_cgs', 'with_dean'] as $status) {
            $appeal->forceFill(['status' => $status])->save();
            $this->today('2027-03-10');
            $this->assertNotContains($id, $this->listed(), "{$status} on the expiry day");
            $this->today('2027-03-20');
            $this->assertNotContains($id, $this->listed(), "{$status} after");
            $this->assertSame('appeal_in_review', $this->code($id));
        }

        $appeal->forceFill(['status' => CandidacyAppeal::STATUS_REJECTED])->save();
        $this->assertContains($id, $this->listed(), 'decided: the student is a candidate once expired');
    }

    public function test_a_dean_rejection_alone_does_not_make_a_student_a_candidate(): void
    {
        $appeal = $this->submitted(3);
        $appeal->forceFill(['status' => CandidacyAppeal::STATUS_REJECTED])->save();
        $this->candidacy->update(['candidacy_expiry_date' => '2027-06-30']);

        $this->today('2027-03-01');
        $this->assertNotContains($this->student->matric_no, $this->listed());
        $this->today('2027-07-01');
        $this->assertContains($this->student->matric_no, $this->listed());
        $this->assertSame('rejected', L::candidates()->firstWhere('studentId', $this->student->matric_no)['outcome']);
    }

    public function test_every_exclusion_is_applied_and_explained(): void
    {
        $this->today('2027-03-20');
        $ok = $this->demo('21020001', '2027-03-01');
        $soft = $this->demo('21020002', '2027-03-01');
        $done = $this->demo('21020003', '2027-03-01');
        $inactive = $this->demo('21020004', '2027-03-01');
        $dismissed = $this->demo('21020005', '2027-03-01');
        $dismissed->update(['status' => 'dismissed']);
        $this->demo('21020006', '2027-03-01', 'MSc in Process Safety (Coursework)');
        $this->demo('21020007', '2027-03-30');
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $soft->id, 'softbound_submitted_at' => '2027-02-01']);
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $done->id, 'completed' => true]);
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $inactive->id, 'inactive' => true]);
        $this->candidacy->update(['candidacy_expiry_date' => '2028-01-01']);

        $this->assertSame(['21020001'], $this->listed());
        $this->assertSame(['softbound', 'completed', 'inactive', 'dismissed', 'coursework', 'not_expired'], array_map(fn ($i) => $this->code("2102000{$i}"), [2, 3, 4, 5, 6, 7]));
        $this->assertSame('candidate', $this->code($ok->matric_no));
    }

    public function test_a_real_student_with_a_softbound_or_completed_record_is_excluded(): void
    {
        $this->today('2027-03-20');
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01']);
        $this->assertContains($this->student->matric_no, $this->listed());

        foreach (['softbound' => 'softbound', 'completed' => 'completed', 'inactive' => 'inactive'] as $status => $code) {
            $this->candidacy->update(['status' => $status]);
            $this->assertNotContains($this->student->matric_no, $this->listed(), $status);
            $this->assertSame($code, $this->code($this->student->matric_no));
        }
    }

    public function test_outcomes(): void
    {
        $this->today('2027-03-20');
        $a = $this->demo('21020010', '2027-03-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2028-01-01']);
        $row = fn () => L::all()->firstWhere('studentId', '21020010');

        $this->assertSame('none', $row()['outcome']);
        $make = fn (string $status, int $n) => CandidacyAppeal::forceCreate(['appeal_no' => "SCA-21020010-0{$n}", 'sequence_no' => $n, 'demo_profile_id' => $a->id, 'status' => $status, 'round' => 1, 'requested_months' => 3, 'expiry_at_submission' => '2027-03-01', 'reason' => 'x', 'submitted_at' => now()]);
        $make('approved', 1);
        $this->assertSame('approved', $row()['outcome']);
        $make('closed_expired', 2);
        $this->assertSame('closed_expired', $row()['outcome']);
    }

    public function test_the_page_is_for_cgs_only_and_the_filters_and_cards_agree(): void
    {
        $this->today('2027-03-20');
        $this->candidacy->update(['candidacy_expiry_date' => '2028-01-01']);
        $this->demo('21020021', '2027-03-01', 'PhD in Chemical Engineering (Full-Time)');
        $this->demo('21020022', '2027-02-01', 'Master of Science in Civil Engineering (Full-Time)');
        $this->demo('21020023', '2027-01-01', 'PhD in Civil Engineering (Full-Time)');
        \App\Modules\Chloe\Models\CandidacyDepartment::create(['code' => 'CEE', 'name' => 'Civil & Environmental Engineering']);

        $this->actingAs($this->student)->get(route('candidacy.cgs.dismissals'))->assertForbidden();
        $this->actingAs($this->lecturer)->get(route('candidacy.cgs.dismissals'))->assertForbidden();

        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals'))->assertOk()
            ->assertSee('Dismissal List')->assertSee('Demo 21020021')->assertSee('Demo 21020023')
            ->assertHeader('Cache-Control')->assertSee('Export all candidates to Excel');
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals', ['level' => 'master']))->assertOk()->assertSee('Demo 21020022')->assertDontSee('Demo 21020021')->assertSee('Export filtered list to Excel');
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals', ['q' => '21020023']))->assertOk()->assertSee('Demo 21020023')->assertDontSee('Demo 21020022');
        $this->assertSame(['candidates' => 3, 'not_exported' => 3, 'exported' => 0], L::counts(L::candidates(), L::filters([])));
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals.live.version'))->assertOk()->assertJsonStructure(['version']);
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals.region'))->assertOk()->assertSee('Candidates');
    }
}
