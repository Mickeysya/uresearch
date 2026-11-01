<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Console\Commands\BacktestAttendance;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\Backtest\AttendanceBacktester;
use App\Modules\Nureen\Support\Backtest\SyntheticAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * attendance:backtest -- the evidence for how well the early warning
 * predicts. In memory: it must never write attendance data or notify.
 */
class AttendanceBacktestTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/attendance-backtest-test-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['nureen.attendance.semester_periods' => 14, 'nureen.attendance.projection_min_periods' => 3]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * Five students over a six-week semester, 10 sessions a week, worked by
     * hand at cut-off 3 (rates in %, projection = least-squares line over
     * periods 1-3 extended to period 6):
     *
     *   A 10 10 10 10 10 10   proj 100.0  actual 100.0  err  0     not at risk   rule -   proj -
     *   B  9  8  7  6  5  4   proj  65.0  actual  65.0  err  0     at risk       rule P3  proj P3
     *   C 10  9  8 10 10 10   proj  75.0  actual  95.0  err 20.0   not at risk   rule P3  proj P3   (both false alarms)
     *   D  8  8  8  8  4  4   proj  80.0  actual  66.7  err 13.3   at risk       rule -   proj -    (both miss)
     *   E 10 10  8  7  6  5   proj  78.3  actual  76.7  err  1.6   at risk       rule -   proj P3
     *
     * MAE = 34.9 / 5 = 6.98. Rule: TP B, FP C, FN D E -> precision 1/2,
     * recall 1/3. Projection (and combined): TP B E, FP C, FN D -> 2/3, 2/3.
     * Over the whole semester: B and D are first flagged in the same period
     * either way; E by the projection at 3, the rule at 4 -> mean lead 1/3.
     */
    protected const TINY = [
        'A' => [10, 10, 10, 10, 10, 10],
        'B' => [9, 8, 7, 6, 5, 4],
        'C' => [10, 9, 8, 10, 10, 10],
        'D' => [8, 8, 8, 8, 4, 4],
        'E' => [10, 10, 8, 7, 6, 5],
    ];

    protected function tinyCsv(): string
    {
        $csv = "matric_no,period_end,sessions_attended,sessions_total\n";
        foreach (self::TINY as $matric => $weeks) {
            foreach ($weeks as $i => $attended) {
                $csv .= $matric.','.CarbonImmutable::parse('2026-01-09')->addWeeks($i)->toDateString().",{$attended},10\n";
            }
        }

        File::put($path = $this->dir.'/tiny.csv', $csv);

        return $path;
    }

    protected function tinyStudents(): array
    {
        return array_map(fn (array $weeks) => array_map(fn ($a, $i) => [
            'attended' => $a, 'total' => 10, 'end' => CarbonImmutable::parse('2026-01-09')->addWeeks($i)->toDateString(),
        ], $weeks, array_keys($weeks)), self::TINY);
    }

    public function test_the_tiny_hand_built_dataset_gives_exactly_the_expected_answers(): void
    {
        $result = (new AttendanceBacktester(6))->run($this->tinyStudents(), [3]);
        $at3 = $result['cutoffs'][0];

        $this->assertSame(5, $result['students']);
        $this->assertSame(3, $result['at_risk']);
        $this->assertSame(5, $at3['projected_n']);
        $this->assertEqualsWithDelta(6.98, $at3['mae'], 1e-9);

        $this->assertSame([1, 1, 2, 1], [$at3['methods']['rule']['tp'], $at3['methods']['rule']['fp'], $at3['methods']['rule']['fn'], $at3['methods']['rule']['tn']]);
        $this->assertEqualsWithDelta(1 / 2, $at3['methods']['rule']['precision'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $at3['methods']['rule']['recall'], 1e-9);

        foreach (['projection', 'combined'] as $method) {
            $this->assertEqualsWithDelta(2 / 3, $at3['methods'][$method]['precision'], 1e-9);
            $this->assertEqualsWithDelta(2 / 3, $at3['methods'][$method]['recall'], 1e-9);
        }

        $this->assertSame(3, $result['earliness']['both_flagged']);
        $this->assertEqualsWithDelta(1 / 3, $result['earliness']['mean_lead'], 1e-9);
        $this->assertSame(1, $result['earliness']['earlier']);
    }

    public function test_the_command_reads_the_tiny_dataset_through_the_upload_parser(): void
    {
        // The original projection window (flag from the first projection,
        // all semester), which the hand-worked answers above assume.
        $this->artisan('attendance:backtest', ['file' => $this->tinyCsv(), '--cutoff' => '3', '--min-periods-to-flag' => '3', '--active-until' => '1', '--output' => $report = $this->dir.'/tiny.md'])
            ->assertSuccessful();

        $md = File::get($report);
        $this->assertStringContainsString('| Source | tiny.csv |', $md);
        $this->assertStringContainsString('| 3 of 6 (50%) | 5 | 6.98 |', $md);
        $this->assertStringContainsString('| 3 of 6 (50%) | Rule alone | 1 | 1 | 2 | 1 | 50.0% | 33.3% | 40.0% |', $md);
        $this->assertStringContainsString('| 3 of 6 (50%) | Projection alone | 2 | 1 | 1 | 1 | 66.7% | 66.7% | 66.7% |', $md);
        $this->assertStringContainsString('| 3 of 6 (50%) | Rule OR projection | 2 | 1 | 1 | 1 | 66.7% | 66.7% | 66.7% |', $md);
        $this->assertStringContainsString('| Mean periods earlier (combined vs rule alone) | 0.33 |', $md);
        $this->assertStringNotContainsString('Synthetic data', $md);
    }

    public function test_the_synthetic_run_reports_every_metric_at_every_cutoff(): void
    {
        $this->artisan('attendance:backtest', ['--synthetic' => true, '--output' => $report = $this->dir.'/syn.md'])
            ->expectsOutputToContain('SYNTHETIC DATA')
            ->expectsOutputToContain('Projection accuracy')
            ->expectsOutputToContain('Earliness')
            ->assertSuccessful();

        $md = File::get($report);

        $this->assertStringContainsString('**Synthetic data.**', $md);
        $this->assertStringContainsString('| Source | Synthetic (seed 2026, 200 students) |', $md);

        // 25%, 50% (the default headline) and 75% of a 14-period semester.
        foreach (['4 of 14 (29%)', '7 of 14 (50%)', '11 of 14 (79%)'] as $cutoff) {
            $this->assertMatchesRegularExpression('/\| '.preg_quote($cutoff).' \| \d+ \| \d+\.\d\d \| \d+\.\d\d \| \d+\.\d\d \|/', $md, "accuracy at {$cutoff}");
            foreach (['Rule alone', 'Projection alone', 'Rule OR projection'] as $method) {
                $this->assertMatchesRegularExpression('/\| '.preg_quote($cutoff).' \| '.$method.' \|( \d+ \|){4} [\d.]+% \| [\d.]+% \| [\d.]+% \|/', $md, "{$method} at {$cutoff}");
            }
        }

        $this->assertMatchesRegularExpression('/\| Mean periods earlier \(combined vs rule alone\) \| \d+\.\d\d \|/', $md);

        foreach (SyntheticAttendance::PATTERNS as $pattern) {
            $this->assertStringContainsString("| {$pattern['label']} |", $md);
        }
    }

    public function test_the_same_seed_gives_identical_results_and_another_seed_does_not(): void
    {
        $run = fn (int $seed) => (new AttendanceBacktester(14))->run((new SyntheticAttendance($seed))->generate(200, 14)['students'], [4, 7, 11]);

        $this->assertSame((new SyntheticAttendance(7))->generate(50, 14), (new SyntheticAttendance(7))->generate(50, 14));
        $this->assertSame($run(2026), $run(2026));
        $this->assertNotSame($run(2026), $run(2027));

        // And through the command: the reports match apart from their timestamp.
        $report = function (string $name) {
            $this->artisan('attendance:backtest', ['--synthetic' => true, '--seed' => 99, '--output' => $path = $this->dir."/{$name}.md"])->assertSuccessful();

            return preg_replace('/^Generated .*$/m', '', File::get($path));
        };
        $this->assertSame($report('first'), $report('second'));
    }

    public function test_the_synthetic_mix_follows_the_documented_proportions(): void
    {
        $this->assertSame([
            'steady_high' => 60, 'steady_borderline' => 30, 'gradual_decline' => 30, 'sudden_drop' => 20,
            'recovery' => 20, 'isolated_bad' => 20, 'noisy_fine' => 20,
        ], SyntheticAttendance::counts(200));
        $this->assertSame(7, array_sum(SyntheticAttendance::counts(7)));

        $help = Artisan::all()['attendance:backtest']->getHelp();
        foreach (SyntheticAttendance::PATTERNS as $pattern) {
            $this->assertStringContainsString($pattern['label'], $help);
            $this->assertStringContainsString(round($pattern['share'] * 100).'%', $help);
        }
    }

    public function test_mean_and_sample_standard_deviation_skip_undefined_runs(): void
    {
        $this->assertEqualsWithDelta(['mean' => 2.5, 'sd' => sqrt(5 / 3), 'n' => 4], BacktestAttendance::stats([1, 2, 3, null, 4]), 1e-12);
        $this->assertSame(['mean' => 7.0, 'sd' => null, 'n' => 1], BacktestAttendance::stats([7.0]));
        $this->assertSame(['mean' => null, 'sd' => null, 'n' => 0], BacktestAttendance::stats([null]));
    }

    public function test_runs_repeat_over_consecutive_seeds_and_report_mean_plus_minus_sd(): void
    {
        $this->artisan('attendance:backtest', ['--synthetic' => true, '--runs' => 3, '--students' => 60, '--output' => $report = $this->dir.'/runs.md'])
            ->expectsOutputToContain('seeds 2026–2028')
            ->assertSuccessful();

        // The mean MAE at the midpoint is the mean of the three seeds' own runs.
        $mae = array_map(fn (int $seed) => (new AttendanceBacktester(14, 3, 80.0, 4, 0.6))
            ->run((new SyntheticAttendance($seed))->generate(60, 14)['students'], [7])['cutoffs'][0]['mae'], [2026, 2027, 2028]);
        $stats = BacktestAttendance::stats($mae);

        $md = File::get($report);
        $this->assertStringContainsString('| Runs | 3 (each metric is mean ± sample standard deviation over the runs) |', $md);
        $this->assertStringContainsString('seeds 2026–2028', $md);
        $this->assertStringContainsString('| 7 of 14 (50%) | '.number_format($stats['mean'], 2).' ± '.number_format($stats['sd'], 2).' |', $md);
        $this->assertStringContainsString('**Synthetic data.**', $md);

        // Repeating a single file would just repeat the same answer.
        $this->artisan('attendance:backtest', ['file' => $this->tinyCsv(), '--runs' => 3])->assertExitCode(2);
    }

    public function test_compare_puts_the_original_and_tuned_windows_side_by_side_on_the_same_data(): void
    {
        $this->artisan('attendance:backtest', ['--synthetic' => true, '--compare' => true, '--output' => $report = $this->dir.'/compare.md'])
            ->expectsOutputToContain('Comparison')
            ->assertSuccessful();

        $students = (new SyntheticAttendance(2026))->generate(200, 14)['students'];
        $at = fn (int $min, float $until) => (new AttendanceBacktester(14, 3, 80.0, $min, $until))->run($students, [7])['cutoffs'][0];
        $original = $at(3, 1.0);
        $tuned = $at(4, 0.6);
        $pct = fn ($v) => number_format(100 * $v, 1).'%';

        $md = File::get($report);
        $this->assertStringContainsString('| Configuration: Original | projection flags from period 3, all semester |', $md);
        $this->assertStringContainsString('| Configuration: Tuned | projection flags from period 4, until 60% of the semester |', $md);
        $this->assertStringContainsString('## Comparison: Original vs Tuned', $md);
        $this->assertStringContainsString(
            '| 7 of 14 (50%) | '
            .$pct($original['methods']['combined']['recall']).' / '.$pct($tuned['methods']['combined']['recall']).' | '
            .$pct($original['methods']['combined']['precision']).' / '.$pct($tuned['methods']['combined']['precision']).' | '
            .$pct($original['methods']['combined']['f1']).' / '.$pct($tuned['methods']['combined']['f1']).' | '
            .number_format($original['mean_lead'], 2).' / '.number_format($tuned['mean_lead'], 2).' |',
            $md,
        );

        // The window changes flagging only, never the projected figure itself.
        $this->assertSame($original['mae'], $tuned['mae']);
    }

    public function test_running_it_writes_no_attendance_data_and_sends_nothing(): void
    {
        Notification::fake();
        config(['nureen.attendance.semester_start' => null]);

        // Real data already on file, including a flagged student with an alert.
        $student = $this->student('SYN0001', 'syn@test.my');
        foreach ([9, 7, 5] as $i => $attended) {
            AttendanceRecord::recordPeriod($student->id, CarbonImmutable::parse('2026-09-04')->addWeeks($i)->toDateString(), $attended, 10);
        }
        app(AttendanceEarlyWarning::class)->run([$student->id]);
        Notification::fake();

        $tables = ['attendance_records', 'attendance_risk_states', 'attendance_alerts', 'notifications', 'jobs'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('attendance:backtest', ['--synthetic' => true, '--output' => $this->dir.'/a.md'])->assertSuccessful();
        $this->artisan('attendance:backtest', ['file' => $this->tinyCsv(), '--output' => $this->dir.'/b.md'])->assertSuccessful();

        $this->assertSame([], $writes);
        $this->assertSame($before, $snapshot());
        Notification::assertNothingSent();
    }

    public function test_it_needs_exactly_one_source_and_reports_a_bad_file(): void
    {
        $this->artisan('attendance:backtest')->assertExitCode(2);
        $this->artisan('attendance:backtest', ['file' => $this->tinyCsv(), '--synthetic' => true])->assertExitCode(2);
        $this->artisan('attendance:backtest', ['file' => $this->dir.'/missing.csv'])->assertFailed();

        File::put($bad = $this->dir.'/bad.csv', "student,date,went,total\nA,2026-01-09,9,10\n");
        $this->artisan('attendance:backtest', ['file' => $bad])
            ->expectsOutputToContain('The first row must be exactly')
            ->assertFailed();
    }

    public function test_a_student_with_an_incomplete_semester_is_left_out_and_noted(): void
    {
        $path = $this->tinyCsv();
        File::append($path, "F,2026-01-09,10,10\n");

        $this->artisan('attendance:backtest', ['file' => $path, '--output' => $report = $this->dir.'/r.md'])->assertSuccessful();

        $md = File::get($report);
        $this->assertStringContainsString('| Students | 5 |', $md);
        $this->assertStringContainsString('1 student(s) left out for not having exactly 6 periods.', $md);
    }
}
