<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Support\CandidacyDismissalList;
use App\Modules\Chloe\Support\CandidacyOperationsQuery;
use App\Modules\Chloe\Support\CandidacyStatistics as S;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Candidacy Insights S1: the statistics definitions (band edges, category priority, population) and agreement with Operations and the Dismissal List. */
class CandidacyStatisticsTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function today(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 09:00:00', 'Asia/Kuala_Lumpur'));
    }

    protected function demo(string $id, string $expiry, string $programme = 'PhD in Information Systems (Full-Time)'): DemoStudentProfile
    {
        return DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => $programme, 'programme_start_date' => '2024-01-01', 'candidacy_expiry_date' => $expiry, 'status' => 'active']);
    }

    protected function row(string $id)
    {
        return S::population()->firstWhere('studentId', $id);
    }

    public function test_the_band_edges_use_the_reminder_milestones_month_arithmetic(): void
    {
        $this->today('2027-01-31');
        $t = CarbonImmutable::parse('2027-01-31', 'Asia/Kuala_Lumpur');
        $band = fn (string $d) => S::bandOf(CarbonImmutable::parse($d, 'Asia/Kuala_Lumpur'), $t);

        $this->assertNull($band('2027-01-30'), 'yesterday: past');
        $this->assertSame('m1', $band('2027-01-31'), 'today');
        $this->assertSame('m1', $band('2027-02-28'), 'today + 1 month (no overflow)');
        $this->assertSame('m2', $band('2027-03-01'));
        $this->assertSame('m2', $band('2027-03-31'), 'today + 2 months');
        $this->assertSame('m3', $band('2027-04-01'));
        $this->assertSame('m3', $band('2027-04-30'), 'today + 3 months');
        $this->assertNull($band('2027-05-01'), 'beyond 3 months');
    }

    public function test_days_left_counts_calendar_dates_and_the_expiry_day_is_zero(): void
    {
        $this->today('2027-03-10');
        $this->demo('21050001', '2027-03-10');
        $this->demo('21050002', '2027-03-20');
        $this->candidacy->update(['candidacy_expiry_date' => '2029-01-01']);

        $this->assertSame(0, $this->row('21050001')['daysLeft']);
        $this->assertSame(10, $this->row('21050002')['daysLeft']);
    }

    public function test_the_category_priority_is_softbound_then_in_review_then_returned_then_none(): void
    {
        $this->today('2027-03-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2027-04-01']);
        $appeal = $this->submitted(3);
        $id = $this->student->matric_no;

        $appeal->forceFill(['status' => 'with_dean'])->save();
        $this->assertSame('in_review', $this->row($id)['category']);

        $appeal->forceFill(['status' => 'returned'])->save();
        $this->assertSame('returned', $this->row($id)['category']);

        $appeal->forceFill(['status' => 'approved'])->save();
        $this->assertSame('none', $this->row($id)['category'], 'a decided appeal changes nothing');

        $appeal->forceFill(['status' => 'with_chair'])->save();
        $this->candidacy->update(['status' => 'softbound']);
        $this->assertSame('softbound', $this->row($id)['category'], 'Softbound beats an appeal in review');
    }

    public function test_the_population_excludes_expired_coursework_dismissed_completed_and_inactive_students(): void
    {
        $this->today('2027-03-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2029-01-01']);
        $this->demo('21050010', '2027-06-01');                                                     // in
        $this->demo('21050011', '2027-02-28');                                                     // expired
        $this->demo('21050012', '2027-06-01', 'MSc in Process Safety (Coursework)');               // coursework
        $this->demo('21050013', '2027-06-01')->update(['status' => 'dismissed']);                  // dismissed
        $done = $this->demo('21050014', '2027-06-01');
        $off = $this->demo('21050015', '2027-06-01');
        $this->demo('21050016', '2027-06-01', 'PhD in Information Systems (Part-Time)');           // part-time stays in
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $done->id, 'completed' => true]);
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $off->id, 'inactive' => true]);

        $ids = S::population()->pluck('studentId')->all();

        $this->assertEqualsCanonicalizing(['21050010', '21050016', $this->student->matric_no], $ids);
        $this->assertSame('part', $this->row('21050016')['mode']);
        $this->assertSame('full', $this->row('21050010')['mode']);
        $this->assertSame('phd', $this->row('21050010')['level']);
    }

    public function test_the_numbers_equal_an_independent_sql_count(): void
    {
        $this->today('2027-03-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-20']);
        foreach (['2027-03-01', '2027-04-01', '2027-04-02', '2027-05-31', '2027-06-01', '2027-06-02', '2028-01-01'] as $i => $d) {
            $this->demo('2105002'.$i, $d);
        }

        $summary = S::summary(S::population());
        $sql = fn (string $from, string $to) => DB::table('demo_student_profiles')->where('status', 'active')->whereBetween('candidacy_expiry_date', [$from, $to])->count()
            + DB::table('study_candidacies')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['softbound', 'completed', 'inactive', 'dismissed']))->whereBetween('candidacy_expiry_date', [$from, $to])->count();

        $this->assertSame($sql('2027-03-01', '2027-06-01'), $summary['within3']);
        $this->assertSame($sql('2027-03-01', '2028-12-31'), $summary['total']);
        $this->assertSame($summary['total'], $summary['by_category']['none'] + $summary['by_category']['softbound'] + $summary['by_category']['in_review'] + $summary['by_category']['returned']);
        $this->assertSame($summary['within3'], array_sum(array_map('array_sum', $summary['bands'])));
        $this->assertSame($summary['by_category']['none'], $summary['high_risk']);
    }

    public function test_the_forecast_is_twelve_zero_filled_months_and_every_grain_adds_up(): void
    {
        $this->today('2027-03-15');
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-20']);
        foreach (['2027-03-31', '2027-05-10', '2027-05-11', '2027-12-01', '2028-02-01'] as $i => $d) {
            $this->demo('2105003'.$i, $d);
        }
        $rows = S::population();

        $months = S::forecast($rows, 'month');
        $this->assertCount(12, $months);
        $this->assertSame('2027-03', $months[0]['period']);
        $this->assertSame('2028-02', $months[11]['period']);
        $this->assertSame(0, $months[1]['total'], 'a month with no expiry is a zero bar');
        $this->assertSame([2, 0, 2], [$months[0]['total'], $months[1]['total'], $months[2]['total']]);

        $this->assertSame($rows->count(), array_sum(array_column(S::forecast($rows, 'quarter'), 'total')));
        $this->assertSame($rows->count(), array_sum(array_column(S::forecast($rows, 'year'), 'total')));
        $this->assertSame(['2027', '2028'], array_column(S::forecast($rows, 'year'), 'period'));
        $this->assertSame('2027-Q1', S::forecast($rows, 'quarter')[0]['period']);
    }

    public function test_appeals_in_progress_and_the_dismissal_counts_are_the_operations_and_dismissal_list_numbers(): void
    {
        $this->today('2027-03-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2029-01-01']);
        $appeal = $this->submitted(3);
        $appeal->forceFill(['status' => 'with_cgs'])->save();
        $this->demo('21050040', '2027-02-01');
        $this->demo('21050041', '2027-01-01');

        $this->assertSame(CandidacyOperationsQuery::counts(CandidacyOperationsQuery::filters([]))['in_progress'], S::appealsInProgress());
        $this->assertSame(1, S::appealsInProgress());

        $pipeline = S::dismissalPipeline();
        $list = CandidacyDismissalList::counts(CandidacyDismissalList::candidates(), CandidacyDismissalList::filters([]));
        $this->assertSame([$list['not_exported'], $list['exported'], $list['candidates']], [$pipeline['not_exported'], $pipeline['exported'], $pipeline['candidates']]);
        $this->assertSame(2, $pipeline['candidates']);
        $this->assertCount(12, $pipeline['dismissed_per_month']);
    }

    public function test_a_reminder_counts_as_answered_when_softbound_or_an_appeal_follows_within_30_days(): void
    {
        $this->today('2027-03-01');
        $a = $this->demo('21050050', '2027-06-01');
        $b = $this->demo('21050051', '2027-06-01');
        $c = $this->demo('21050052', '2027-06-01');
        $this->candidacy->update(['candidacy_expiry_date' => '2029-01-01']);
        foreach ([$a, $b, $c] as $p) {
            \App\Modules\Chloe\Models\CandidacyReminderLog::create(['demo_profile_id' => $p->id, 'expiry_date' => '2027-06-01', 'milestone' => 3, 'variant' => 'A', 'status' => 'sent', 'sent_at' => '2027-02-10 08:00:00']);
        }
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $a->id, 'softbound_submitted_at' => '2027-02-25']);          // 15 days later
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $c->id, 'softbound_submitted_at' => '2027-03-15']);          // 33 days later: too late

        $rows = collect(S::reminderRows())->keyBy(fn ($r) => $r['category'].$r['date'].$r['responded']);
        $responded = collect(S::reminderRows())->where('responded', true)->count();

        $this->assertSame(1, $responded);
        $this->assertCount(3, S::reminderRows());
        $this->assertSame('2027-02', S::reminderRows()[0]['month']);
    }
}
