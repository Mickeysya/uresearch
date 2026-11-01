<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Nureen\Support\AttendanceProjector;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use App\Modules\Nureen\Support\AttendanceSheet;
use App\Modules\Nureen\Support\Backtest\AttendanceBacktester;
use App\Modules\Nureen\Support\Backtest\SyntheticAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Evidence for how well the attendance early warning predicts: replay
 * complete past semesters with the later periods hidden, and compare what
 * the rule and the projection said with what actually happened. See
 * Support\Backtest\AttendanceBacktester for every definition.
 *
 * In memory only. It reads a file (or generates the synthetic dataset) and
 * writes a Markdown report under storage/app/backtest/; it never touches
 * attendance_records, attendance_risk_states or attendance_alerts, and
 * never sends a notification.
 */
class BacktestAttendance extends Command
{
    protected $signature = 'attendance:backtest
        {file? : A full-semester export in the manual-upload format (matric_no, period_end, sessions_attended, sessions_total)}
        {--synthetic : Use the built-in synthetic dataset instead of a file}
        {--cutoff=50% : The headline cut-off: a period number, or a share of the semester such as 50%}
        {--periods= : Semester length in periods (default: nureen.attendance.semester_periods for synthetic data; the longest history in a file)}
        {--students=200 : Synthetic only: how many students}
        {--seed=2026 : Synthetic only: random seed (same seed, same dataset)}
        {--output= : Write the Markdown report here instead of storage/app/backtest/}';

    protected $description = 'Backtest the attendance projection and rule against complete semesters (in memory; nothing is written to attendance data)';

    /** Always reported alongside the headline cut-off, to show accuracy improving as data arrives. */
    public const SWEEP = ['25%', '50%', '75%'];

    protected function configure(): void
    {
        parent::configure();

        $patterns = collect(SyntheticAttendance::PATTERNS)
            ->map(fn ($p) => sprintf('  %-30s %3d%%  %s', $p['label'], round($p['share'] * 100), $p['shape']))
            ->implode("\n");

        $this->setHelp(<<<HELP
            Hides every period after the cut-off, replays the live checks (the
            original rule after each visible period, and the end-of-semester
            projection), and compares them with each student's ACTUAL final
            attendance. "Truly at risk" means an actual final below 80%.

            Reports MAE and RMSE of projected vs actual final %, precision,
            recall and F1 for the rule alone, the projection alone and both
            combined, and how many periods earlier the combined approach
            flagged truly at-risk students. Always at 25%, 50% and 75% of the
            semester, plus --cutoff if different.

            --synthetic generates a reproducible dataset (fixed --seed). Every
            student follows one pattern, in these proportions:

            {$patterns}

            Each period gets uniform noise of ±4 points (±12 for "noisy but fine"),
            clamped to 0-100%, at 10 sessions a period. Synthetic matric numbers
            are SYN0001 onwards. Reports from it are labelled SYNTHETIC.
            HELP);
    }

    public function handle(): int
    {
        $synthetic = (bool) $this->option('synthetic');

        if ($synthetic === ($this->argument('file') !== null)) {
            $this->error('Give either a file or --synthetic (not both, not neither).');

            return self::INVALID;
        }

        $dataset = $synthetic ? $this->syntheticDataset() : $this->fileDataset((string) $this->argument('file'));

        if ($dataset === null) {
            return self::FAILURE;
        }

        if (count($dataset['students']) === 0) {
            $this->error('No student has a complete semester of '.$dataset['periods'].' periods to test against.');

            return self::FAILURE;
        }

        $periods = $dataset['periods'];
        $headline = AttendanceBacktester::cutoffPeriod((string) $this->option('cutoff'), $periods);
        $cutoffs = collect(self::SWEEP)
            ->map(fn ($c) => AttendanceBacktester::cutoffPeriod($c, $periods))
            ->push($headline)
            ->unique()->sort()->values()->all();

        $backtester = new AttendanceBacktester($periods, (int) config('nureen.attendance.projection_min_periods', 3));
        $result = $backtester->run($dataset['students'], $cutoffs);

        $this->printResults($dataset, $result, $headline);

        $path = $this->option('output') ?: storage_path('app/backtest/attendance-backtest-'.$dataset['slug'].'-'.now()->format('Ymd-His').'.md');
        File::ensureDirectoryExists(dirname($path));

        // Generated reports stay out of Source Control without touching the
        // shared root .gitignore.
        if (! $this->option('output') && ! File::exists($ignore = storage_path('app/backtest/.gitignore'))) {
            File::put($ignore, "*\n");
        }
        File::put($path, $this->markdown($dataset, $result, $headline));
        $this->newLine();
        $this->info("Report written to {$path}");

        return self::SUCCESS;
    }

    /* -----------------------------------------------------------------
     | Datasets
     |------------------------------------------------------------------*/

    private function syntheticDataset(): array
    {
        $periods = (int) ($this->option('periods') ?: config('nureen.attendance.semester_periods', 14));
        $seed = (int) $this->option('seed');
        $students = max(1, (int) $this->option('students'));
        $data = (new SyntheticAttendance($seed))->generate($students, $periods);

        return [
            'synthetic' => true,
            'name' => "Synthetic (seed {$seed}, {$students} students)",
            'slug' => "synthetic-seed{$seed}",
            'students' => $data['students'],
            'counts' => $data['counts'],
            'periods' => $periods,
            'notes' => [],
        ];
    }

    private function fileDataset(string $file): ?array
    {
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("No such file: {$file}");

            return null;
        }

        // The same parser and header check as the manual upload.
        $read = AttendanceSheet::readFile($path);

        if ($read['error']) {
            $this->error($read['error']);

            return null;
        }

        $byStudent = [];
        $rejected = 0;

        foreach ($read['rows'] as $row) {
            $matric = trim((string) $row['matric_no']);
            $end = AttendanceSheet::parseDate($row['period_end']);
            $attended = filter_var($row['sessions_attended'], FILTER_VALIDATE_INT);
            $total = filter_var($row['sessions_total'], FILTER_VALIDATE_INT);

            if ($matric === '' || ! $end || $attended === false || $total === false || $attended < 0 || $total < 0 || $attended > $total) {
                $rejected++;

                continue;
            }

            // A period given twice: the later row wins, as recordPeriod() does.
            $byStudent[$matric][$end] = ['attended' => $attended, 'total' => $total, 'end' => $end];
        }

        $periods = (int) ($this->option('periods') ?: max(array_map('count', $byStudent ?: [[]])));
        $students = [];
        $incomplete = 0;

        foreach ($byStudent as $matric => $rows) {
            ksort($rows);

            if (count($rows) !== $periods) {
                $incomplete++;

                continue;
            }

            $students[$matric] = array_values($rows);
        }

        $notes = [];
        if ($rejected) {
            $notes[] = "{$rejected} row(s) skipped as invalid (the same checks as the upload).";
        }
        if ($incomplete) {
            $notes[] = "{$incomplete} student(s) left out for not having exactly {$periods} periods.";
        }

        return [
            'synthetic' => false,
            'name' => basename($path),
            'slug' => pathinfo($path, PATHINFO_FILENAME),
            'students' => $students,
            'counts' => null,
            'periods' => $periods,
            'notes' => $notes,
        ];
    }

    /* -----------------------------------------------------------------
     | Output
     |------------------------------------------------------------------*/

    private static function pct(?float $v): string
    {
        return $v === null ? 'n/a' : number_format(100 * $v, 1).'%';
    }

    private static function num(?float $v, int $dp = 2): string
    {
        return $v === null ? 'n/a' : number_format($v, $dp);
    }

    private static function cutoffLabel(array $c, int $periods): string
    {
        return sprintf('%d of %d (%d%%)', $c['cutoff'], $periods, round(100 * $c['share']));
    }

    private function printResults(array $dataset, array $result, int $headline): void
    {
        $periods = $dataset['periods'];

        if ($dataset['synthetic']) {
            $this->warn('SYNTHETIC DATA: generated students, not real CGS records.');
        }

        $this->line("Dataset: {$dataset['name']} · {$result['students']} students · {$periods} periods · {$result['at_risk']} truly at risk (actual final below 80%)");
        foreach ($dataset['notes'] as $note) {
            $this->line("  {$note}");
        }

        $this->newLine();
        $this->line('<options=bold>Projection accuracy</> (percentage points; baseline = attendance so far carried forward)');
        $this->table(
            ['Cut-off', 'Projected', 'MAE', 'RMSE', 'Baseline MAE'],
            array_map(fn ($c) => [
                self::cutoffLabel($c, $periods).($c['cutoff'] === $headline ? ' *' : ''),
                $c['projected_n'],
                self::num($c['mae']),
                self::num($c['rmse']),
                self::num($c['baseline_mae']),
            ], $result['cutoffs']),
        );

        $this->line('<options=bold>Flagging truly at-risk students</> (precision / recall / F1; flagged at any check up to the cut-off)');
        $this->table(
            ['Cut-off', 'Rule alone', 'Projection alone', 'Rule OR projection'],
            array_map(fn ($c) => [
                self::cutoffLabel($c, $periods).($c['cutoff'] === $headline ? ' *' : ''),
                ...array_map(fn ($m) => self::pct($m['precision']).' / '.self::pct($m['recall']).' / '.self::pct($m['f1']), array_values($c['methods'])),
            ], $result['cutoffs']),
        );

        $e = $result['earliness'];
        $this->line('<options=bold>Earliness</> (whole semester, truly at-risk students)');
        $this->line(sprintf(
            '  Flagged by both: %d, the combined approach on average %s period(s) earlier (earlier for %d of them).',
            $e['both_flagged'], self::num($e['mean_lead']), $e['earlier'],
        ));
        $this->line("  Flagged only by the combined approach (never by the rule): {$e['only_combined']}. Missed by both: {$e['missed']}.");
        $this->line('  * headline cut-off');
    }

    private function markdown(array $dataset, array $result, int $headline): string
    {
        $periods = $dataset['periods'];
        $minPeriods = (int) config('nureen.attendance.projection_min_periods', 3);
        $threshold = AttendanceRiskEvaluator::THRESHOLD;
        $periodLength = AttendanceProjector::periodLength(array_column(reset($dataset['students']), 'end')) ?? 'mixed';
        $md = [];

        $md[] = '# Attendance early warning: backtest';
        $md[] = '';
        $md[] = 'Generated '.now()->format('j F Y, H:i').' by `php artisan attendance:backtest`.';
        $md[] = '';

        if ($dataset['synthetic']) {
            $md[] = '> **Synthetic data.** These results come from a generated dataset of simulated students, not from real CGS records. They show how the method behaves on the patterns described below; they are not evidence of performance on real UTP students.';
            $md[] = '';
        }

        $md[] = '## Dataset';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        $md[] = "| Source | {$dataset['name']} |";
        $md[] = "| Students | {$result['students']} |";
        $md[] = "| Semester length | {$periods} {$periodLength} periods |";
        $md[] = "| Truly at risk (actual final below {$threshold}%) | {$result['at_risk']} (".self::pct($result['students'] ? $result['at_risk'] / $result['students'] : null).') |';
        $md[] = '| Cut-offs | '.implode(', ', array_map(fn ($c) => self::cutoffLabel($c, $periods), $result['cutoffs'])).' (headline: period '.$headline.') |';
        foreach ($dataset['notes'] as $note) {
            $md[] = "| Note | {$note} |";
        }
        $md[] = '';

        if ($dataset['counts']) {
            $md[] = 'Synthetic patterns (uniform noise of ±'.SyntheticAttendance::NOISE.' points per period, ±'.SyntheticAttendance::NOISY_FINE_NOISE.' for "noisy but fine"; '.SyntheticAttendance::SESSIONS_PER_PERIOD.' sessions a period):';
            $md[] = '';
            $md[] = '| Pattern | Students | Shape |';
            $md[] = '|---|---|---|';
            foreach (SyntheticAttendance::PATTERNS as $key => $p) {
                $md[] = "| {$p['label']} | {$dataset['counts'][$key]} | {$p['shape']} |";
            }
            $md[] = '';
        }

        $md[] = '## Method';
        $md[] = '';
        $md[] = '- For each cut-off, every period after it is hidden. The system\'s checks are replayed as if they had run after each visible period, using the live code unchanged: the original rule (`AttendanceRiskEvaluator::ruleFlags()`: a period below '.$threshold.'%, or three declining periods ending at or below '.($threshold + AttendanceRiskEvaluator::EARLY_WARNING_BAND).'%) and the least-squares projection (`AttendanceProjector`, from period '.$minPeriods.' onwards).';
        $md[] = '- **Truly at risk:** the student\'s actual final attendance over the whole semester is below '.$threshold.'%.';
        $md[] = '- **Flagged by the cut-off:** the method flagged the student at any check up to and including the cut-off period (the live system emails on the first flag). The projection flags when the projected end-of-semester figure is below '.$threshold.'%. "Rule OR projection" is what the system uses.';
        $md[] = '- **MAE / RMSE:** absolute and root-mean-squared difference, in percentage points, between the projection made at the cut-off and the actual final attendance, over students with a projection at that point. The **baseline** assumes the attendance so far simply continues.';
        $md[] = '- **Earliness:** over the whole semester, the first period the combined approach flagged each truly at-risk student, against the first period the rule alone did.';
        $md[] = '';

        $md[] = '## Projection accuracy';
        $md[] = '';
        $md[] = '| Cut-off | Students projected | MAE (pts) | RMSE (pts) | Baseline MAE (pts) |';
        $md[] = '|---|---|---|---|---|';
        foreach ($result['cutoffs'] as $c) {
            $md[] = '| '.self::cutoffLabel($c, $periods).' | '.$c['projected_n'].' | '.self::num($c['mae']).' | '.self::num($c['rmse']).' | '.self::num($c['baseline_mae']).' |';
        }
        $md[] = '';

        $md[] = '## Flagging truly at-risk students';
        $md[] = '';
        $md[] = '| Cut-off | Method | TP | FP | FN | TN | Precision | Recall | F1 |';
        $md[] = '|---|---|---|---|---|---|---|---|---|';
        $names = ['rule' => 'Rule alone', 'projection' => 'Projection alone', 'combined' => 'Rule OR projection'];
        foreach ($result['cutoffs'] as $c) {
            foreach ($c['methods'] as $method => $m) {
                $md[] = '| '.self::cutoffLabel($c, $periods)." | {$names[$method]} | {$m['tp']} | {$m['fp']} | {$m['fn']} | {$m['tn']} | ".self::pct($m['precision']).' | '.self::pct($m['recall']).' | '.self::pct($m['f1']).' |';
            }
        }
        $md[] = '';

        $e = $result['earliness'];
        $md[] = '## Earliness';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        $md[] = "| Truly at-risk students flagged by both | {$e['both_flagged']} |";
        $md[] = '| Mean periods earlier (combined vs rule alone) | '.self::num($e['mean_lead']).' |';
        $md[] = "| Flagged earlier by the combined approach | {$e['earlier']} |";
        $md[] = "| Flagged only by the combined approach | {$e['only_combined']} |";
        $md[] = "| Missed by both | {$e['missed']} |";
        $md[] = '';

        return implode("\n", $md);
    }
}
