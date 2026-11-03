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
 * --runs repeats a synthetic backtest over consecutive seeds and reports
 * each metric as mean ± sample standard deviation; --compare runs the
 * ORIGINAL projection window (flag from the first projection, all
 * semester) beside the configured one on the very same datasets.
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
        {--seed=2026 : Synthetic only: random seed (same seed, same dataset); with --runs, the first seed}
        {--runs=1 : Synthetic only: repeat over this many consecutive seeds and report mean ± standard deviation}
        {--min-periods-to-flag= : Override nureen.attendance.projection_min_periods_to_flag}
        {--active-until= : Override nureen.attendance.projection_active_until (share of the semester, 0-1)}
        {--compare : Also run the original window (flag from the first projection, all semester) and compare side by side}
        {--output= : Write the Markdown report here instead of storage/app/backtest/}';

    protected $description = 'Backtest the attendance projection and rule against complete semesters (in memory; nothing is written to attendance data)';

    /** Always reported alongside the headline cut-off, to show accuracy improving as data arrives. */
    public const SWEEP = ['25%', '50%', '75%'];

    protected const METHOD_NAMES = ['rule' => 'Rule alone', 'projection' => 'Projection alone', 'combined' => 'Rule OR projection'];

    protected function configure(): void
    {
        parent::configure();

        $patterns = collect(SyntheticAttendance::PATTERNS)
            ->map(fn ($p) => sprintf('  %-30s %3d%%  %s', $p['label'], round($p['share'] * 100), $p['shape']))
            ->implode("\n");

        $this->setHelp(<<<HELP
            Hides every period after the cut-off, replays the live checks (the
            original rule after each visible period, and the end-of-semester
            projection inside its configured window) and compares them with each
            student's ACTUAL final attendance. "Truly at risk" means an actual
            final below 80%.

            Reports MAE and RMSE of projected vs actual final %, precision,
            recall and F1 for the rule alone, the projection alone and both
            combined, and how many periods earlier the combined approach
            flagged truly at-risk students. Always at 25%, 50% and 75% of the
            semester, plus --cutoff if different.

            --runs=N repeats a synthetic backtest over N seeds (--seed, --seed+1,
            ...) and reports mean ± sample standard deviation. --compare adds the
            original projection window beside the configured one, on the same data.

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
        $runs = max(1, (int) $this->option('runs'));

        if ($synthetic === ($this->argument('file') !== null)) {
            $this->error('Give either a file or --synthetic (not both, not neither).');

            return self::INVALID;
        }

        if ($runs > 1 && ! $synthetic) {
            $this->error('--runs repeats the synthetic dataset over different seeds; it needs --synthetic.');

            return self::INVALID;
        }

        $datasets = [];
        for ($i = 0; $i < $runs; $i++) {
            $dataset = $synthetic ? $this->syntheticDataset((int) $this->option('seed') + $i) : $this->fileDataset((string) $this->argument('file'));

            if ($dataset === null) {
                return self::FAILURE;
            }

            if (count($dataset['students']) === 0) {
                $this->error('No student has a complete semester of '.$dataset['periods'].' periods to test against.');

                return self::FAILURE;
            }

            $datasets[] = $dataset;
        }

        $first = $datasets[0];
        $periods = $first['periods'];
        $headline = AttendanceBacktester::cutoffPeriod((string) $this->option('cutoff'), $periods);
        $cutoffs = collect(self::SWEEP)
            ->map(fn ($c) => AttendanceBacktester::cutoffPeriod($c, $periods))
            ->push($headline)
            ->unique()->sort()->values()->all();

        $configs = $this->configs();
        $results = [];
        foreach ($configs as $key => $config) {
            $backtester = new AttendanceBacktester($periods, $config['min_periods'], AttendanceRiskEvaluator::THRESHOLD, $config['min_to_flag'], $config['active_until']);
            foreach ($datasets as $dataset) {
                $results[$key][] = $backtester->run($dataset['students'], $cutoffs);
            }
        }

        if ($runs === 1 && count($configs) === 1) {
            $result = $results[array_key_first($configs)][0];
            $config = reset($configs);
            $this->printResults($first, $result, $headline, $config);
            $markdown = $this->markdown($first, $result, $headline, $config);
        } else {
            $summaries = array_map(fn ($r) => $this->summarise($r), $results);
            $this->printSummaries($datasets, $configs, $summaries, $headline);
            $markdown = $this->summaryMarkdown($datasets, $configs, $summaries, $headline);
        }

        $slug = $first['slug'].($runs > 1 ? "-{$runs}runs" : '').(count($configs) > 1 ? '-compare' : '');
        $path = $this->option('output') ?: storage_path('app/backtest/attendance-backtest-'.$slug.'-'.now()->format('Ymd-His').'.md');
        File::ensureDirectoryExists(dirname($path));

        // Generated reports stay out of Source Control without touching the
        // shared root .gitignore.
        if (! $this->option('output') && ! File::exists($ignore = storage_path('app/backtest/.gitignore'))) {
            File::put($ignore, "*\n");
        }

        File::put($path, $markdown);
        $this->newLine();
        $this->info("Report written to {$path}");

        return self::SUCCESS;
    }

    /**
     * The projection windows to test: the configured one (or the
     * --min-periods-to-flag / --active-until overrides), and with --compare
     * the original one before it.
     *
     * @return array<string, array{label: string, min_periods: int, min_to_flag: int, active_until: float}>
     */
    private function configs(): array
    {
        $minPeriods = (int) config('nureen.attendance.projection_min_periods', 3);
        $configured = [
            'min_periods' => $minPeriods,
            'min_to_flag' => (int) ($this->option('min-periods-to-flag') ?? config('nureen.attendance.projection_min_periods_to_flag', 4)),
            'active_until' => (float) ($this->option('active-until') ?? config('nureen.attendance.projection_active_until', 0.6)),
        ];

        if (! $this->option('compare')) {
            return ['configured' => ['label' => 'Configured'] + $configured];
        }

        return [
            'original' => ['label' => 'Original', 'min_periods' => $minPeriods, 'min_to_flag' => $minPeriods, 'active_until' => 1.0],
            'tuned' => ['label' => 'Tuned'] + $configured,
        ];
    }

    private static function configText(array $config): string
    {
        return "projection flags from period {$config['min_to_flag']}, "
            .($config['active_until'] >= 1.0 ? 'all semester' : 'until '.round(100 * $config['active_until']).'% of the semester');
    }

    /* -----------------------------------------------------------------
     | Datasets
     |------------------------------------------------------------------*/

    private function syntheticDataset(int $seed): array
    {
        $periods = (int) ($this->option('periods') ?: config('nureen.attendance.semester_periods', 14));
        $students = max(1, (int) $this->option('students'));
        $data = (new SyntheticAttendance($seed))->generate($students, $periods);

        return [
            'synthetic' => true,
            'seed' => $seed,
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
            'seed' => null,
            'name' => basename($path),
            'slug' => pathinfo($path, PATHINFO_FILENAME),
            'students' => $students,
            'counts' => null,
            'periods' => $periods,
            'notes' => $notes,
        ];
    }

    /* -----------------------------------------------------------------
     | Several runs: mean ± standard deviation
     |------------------------------------------------------------------*/

    /**
     * Mean and sample standard deviation of one metric over the runs where
     * it is defined (precision is undefined when nothing was flagged).
     *
     * @param  array<int, ?float>  $values
     * @return array{mean: ?float, sd: ?float, n: int}
     */
    public static function stats(array $values): array
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        $n = count($values);

        if ($n === 0) {
            return ['mean' => null, 'sd' => null, 'n' => 0];
        }

        $mean = array_sum($values) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1)) : null;

        return ['mean' => $mean, 'sd' => $sd, 'n' => $n];
    }

    /** Every metric, per cut-off, as stats() over the runs of one configuration. */
    private function summarise(array $runs): array
    {
        $cutoffs = [];

        foreach (array_keys($runs[0]['cutoffs']) as $i) {
            $at = array_column(array_column($runs, 'cutoffs'), $i);
            $methods = [];

            foreach (array_keys(self::METHOD_NAMES) as $method) {
                foreach (['precision', 'recall', 'f1'] as $metric) {
                    $methods[$method][$metric] = self::stats(array_map(fn ($c) => $c['methods'][$method][$metric], $at));
                }
            }

            $cutoffs[] = [
                'cutoff' => $at[0]['cutoff'],
                'share' => $at[0]['share'],
                'mae' => self::stats(array_column($at, 'mae')),
                'rmse' => self::stats(array_column($at, 'rmse')),
                'baseline_mae' => self::stats(array_column($at, 'baseline_mae')),
                'mean_lead' => self::stats(array_column($at, 'mean_lead')),
                'methods' => $methods,
            ];
        }

        return [
            'runs' => count($runs),
            'students' => $runs[0]['students'],
            'at_risk' => self::stats(array_column($runs, 'at_risk')),
            'cutoffs' => $cutoffs,
            'lead' => self::stats(array_column(array_column($runs, 'earliness'), 'mean_lead')),
            'only_combined' => self::stats(array_column(array_column($runs, 'earliness'), 'only_combined')),
            'missed' => self::stats(array_column(array_column($runs, 'earliness'), 'missed')),
        ];
    }

    private static function ms(array $s, bool $percent, int $runs): string
    {
        if ($s['mean'] === null) {
            return 'n/a';
        }

        $scale = $percent ? 100 : 1;
        $dp = $percent ? 1 : 2;
        $text = number_format($s['mean'] * $scale, $dp).($s['sd'] !== null ? ' ± '.number_format($s['sd'] * $scale, $dp) : '').($percent ? '%' : '');

        return $s['n'] < $runs ? "{$text} (n={$s['n']})" : $text;
    }

    private function printSummaries(array $datasets, array $configs, array $summaries, int $headline): void
    {
        $first = $datasets[0];
        $periods = $first['periods'];
        $runs = count($datasets);

        if ($first['synthetic']) {
            $this->warn('SYNTHETIC DATA: generated students, not real CGS records.');
        }

        $source = $first['synthetic']
            ? 'Synthetic, '.count($first['students']).' students per run, seeds '.$first['seed'].'–'.end($datasets)['seed']
            : $first['name'];
        $this->line("Dataset: {$source} · {$runs} run(s) · mean ± sample SD");

        foreach ($configs as $key => $config) {
            $s = $summaries[$key];
            $this->newLine();
            $this->line("<options=bold>{$config['label']}</>: ".self::configText($config).'; truly at risk per run '.self::ms($s['at_risk'], false, $runs));
            $this->table(
                ['Cut-off', 'MAE', 'Baseline MAE', ...array_values(self::METHOD_NAMES)],
                array_map(fn ($c) => [
                    self::cutoffLabel($c, $periods).($c['cutoff'] === $headline ? ' *' : ''),
                    self::ms($c['mae'], false, $runs),
                    self::ms($c['baseline_mae'], false, $runs),
                    ...array_map(fn ($m) => 'R '.self::ms($m['recall'], true, $runs).' · P '.self::ms($m['precision'], true, $runs), array_values($c['methods'])),
                ], $s['cutoffs']),
            );
        }

        if (count($configs) > 1) {
            [$a, $b] = array_keys($configs);
            $this->line("<options=bold>Comparison</>: rule OR projection (what the system uses), {$configs[$a]['label']} → {$configs[$b]['label']}");
            $this->table(
                ['Cut-off', 'Recall', 'Precision', 'F1', 'Periods earlier'],
                $this->comparisonRows($summaries[$a], $summaries[$b], $periods, $runs, ' → '),
            );
        }

        $this->line('  * headline cut-off. Periods earlier: truly at-risk students flagged by both approaches by the cut-off.');
    }

    private function comparisonRows(array $a, array $b, int $periods, int $runs, string $separator): array
    {
        $rows = [];

        foreach ($a['cutoffs'] as $i => $c) {
            $d = $b['cutoffs'][$i];
            $rows[] = [
                self::cutoffLabel($c, $periods),
                self::ms($c['methods']['combined']['recall'], true, $runs).$separator.self::ms($d['methods']['combined']['recall'], true, $runs),
                self::ms($c['methods']['combined']['precision'], true, $runs).$separator.self::ms($d['methods']['combined']['precision'], true, $runs),
                self::ms($c['methods']['combined']['f1'], true, $runs).$separator.self::ms($d['methods']['combined']['f1'], true, $runs),
                self::ms($c['mean_lead'], false, $runs).$separator.self::ms($d['mean_lead'], false, $runs),
            ];
        }

        return $rows;
    }

    private function summaryMarkdown(array $datasets, array $configs, array $summaries, int $headline): string
    {
        $first = $datasets[0];
        $periods = $first['periods'];
        $runs = count($datasets);
        $md = $this->markdownHeader($first);

        $md[] = '## Dataset';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        $md[] = '| Source | '.($first['synthetic']
            ? 'Synthetic, '.count($first['students']).' students per run, seeds '.$first['seed'].'–'.end($datasets)['seed']
            : $first['name']).' |';
        $md[] = "| Runs | {$runs} (each metric is mean ± sample standard deviation over the runs) |";
        $md[] = "| Semester length | {$periods} periods |";
        $md[] = '| Truly at risk per run | '.self::ms(reset($summaries)['at_risk'], false, $runs).' |';
        $md[] = '| Cut-offs | '.implode(', ', array_map(fn ($c) => self::cutoffLabel($c, $periods), reset($summaries)['cutoffs'])).' (headline: period '.$headline.') |';
        foreach ($configs as $config) {
            $md[] = "| Configuration: {$config['label']} | ".self::configText($config).' |';
        }
        $md[] = '';

        $md = [...$md, ...$this->patternsMarkdown($first), ...$this->methodMarkdown()];
        $md[] = '- A value marked (n=k) was defined in only k of the runs (precision is undefined when a method flagged nobody).';
        $md[] = '';

        if (count($configs) > 1) {
            [$a, $b] = array_keys($configs);
            $md[] = "## Comparison: {$configs[$a]['label']} vs {$configs[$b]['label']}";
            $md[] = '';
            $md[] = 'Rule OR projection (what the system uses). Each cell: '.$configs[$a]['label'].' / '.$configs[$b]['label'].'.';
            $md[] = '';
            $md[] = '| Cut-off | Recall | Precision | F1 | Periods earlier |';
            $md[] = '|---|---|---|---|---|';
            foreach ($this->comparisonRows($summaries[$a], $summaries[$b], $periods, $runs, ' / ') as $row) {
                $md[] = '| '.implode(' | ', $row).' |';
            }
            $md[] = '';
        }

        foreach ($configs as $key => $config) {
            $s = $summaries[$key];
            $md[] = "## {$config['label']}: ".self::configText($config);
            $md[] = '';
            $md[] = '| Cut-off | MAE (pts) | RMSE (pts) | Baseline MAE (pts) | Periods earlier |';
            $md[] = '|---|---|---|---|---|';
            foreach ($s['cutoffs'] as $c) {
                $md[] = '| '.self::cutoffLabel($c, $periods).' | '.self::ms($c['mae'], false, $runs).' | '.self::ms($c['rmse'], false, $runs).' | '.self::ms($c['baseline_mae'], false, $runs).' | '.self::ms($c['mean_lead'], false, $runs).' |';
            }
            $md[] = '';
            $md[] = '| Cut-off | Method | Precision | Recall | F1 |';
            $md[] = '|---|---|---|---|---|';
            foreach ($s['cutoffs'] as $c) {
                foreach ($c['methods'] as $method => $m) {
                    $md[] = '| '.self::cutoffLabel($c, $periods).' | '.self::METHOD_NAMES[$method].' | '.self::ms($m['precision'], true, $runs).' | '.self::ms($m['recall'], true, $runs).' | '.self::ms($m['f1'], true, $runs).' |';
                }
            }
            $md[] = '';
            $md[] = 'Whole semester: combined approach '.self::ms($s['lead'], false, $runs).' periods earlier than the rule alone; flagged only by the combined approach '.self::ms($s['only_combined'], false, $runs).', missed by both '.self::ms($s['missed'], false, $runs).' students per run.';
            $md[] = '';
        }

        return implode("\n", $md);
    }

    /* -----------------------------------------------------------------
     | One run
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

    private function printResults(array $dataset, array $result, int $headline, array $config): void
    {
        $periods = $dataset['periods'];

        if ($dataset['synthetic']) {
            $this->warn('SYNTHETIC DATA: generated students, not real CGS records.');
        }

        $this->line("Dataset: {$dataset['name']} · {$result['students']} students · {$periods} periods · {$result['at_risk']} truly at risk (actual final below 80%)");
        $this->line('Configuration: '.self::configText($config));
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
            ['Cut-off', ...array_values(self::METHOD_NAMES), 'Periods earlier'],
            array_map(fn ($c) => [
                self::cutoffLabel($c, $periods).($c['cutoff'] === $headline ? ' *' : ''),
                ...array_map(fn ($m) => self::pct($m['precision']).' / '.self::pct($m['recall']).' / '.self::pct($m['f1']), array_values($c['methods'])),
                self::num($c['mean_lead']),
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

    private function markdown(array $dataset, array $result, int $headline, array $config): string
    {
        $periods = $dataset['periods'];
        $threshold = AttendanceRiskEvaluator::THRESHOLD;
        $periodLength = AttendanceProjector::periodLength(array_column(reset($dataset['students']), 'end')) ?? 'mixed';
        $md = $this->markdownHeader($dataset);

        $md[] = '## Dataset';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        $md[] = "| Source | {$dataset['name']} |";
        $md[] = "| Students | {$result['students']} |";
        $md[] = "| Semester length | {$periods} {$periodLength} periods |";
        $md[] = "| Truly at risk (actual final below {$threshold}%) | {$result['at_risk']} (".self::pct($result['students'] ? $result['at_risk'] / $result['students'] : null).') |';
        $md[] = '| Cut-offs | '.implode(', ', array_map(fn ($c) => self::cutoffLabel($c, $periods), $result['cutoffs'])).' (headline: period '.$headline.') |';
        $md[] = '| Configuration | '.self::configText($config).' |';
        foreach ($dataset['notes'] as $note) {
            $md[] = "| Note | {$note} |";
        }
        $md[] = '';

        $md = [...$md, ...$this->patternsMarkdown($dataset), ...$this->methodMarkdown()];
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
        foreach ($result['cutoffs'] as $c) {
            foreach ($c['methods'] as $method => $m) {
                $md[] = '| '.self::cutoffLabel($c, $periods).' | '.self::METHOD_NAMES[$method]." | {$m['tp']} | {$m['fp']} | {$m['fn']} | {$m['tn']} | ".self::pct($m['precision']).' | '.self::pct($m['recall']).' | '.self::pct($m['f1']).' |';
            }
        }
        $md[] = '';

        $e = $result['earliness'];
        $md[] = '## Earliness';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        foreach ($result['cutoffs'] as $c) {
            $md[] = '| Mean periods earlier by '.self::cutoffLabel($c, $periods).' | '.self::num($c['mean_lead']).' |';
        }
        $md[] = "| Truly at-risk students flagged by both | {$e['both_flagged']} |";
        $md[] = '| Mean periods earlier (combined vs rule alone) | '.self::num($e['mean_lead']).' |';
        $md[] = "| Flagged earlier by the combined approach | {$e['earlier']} |";
        $md[] = "| Flagged only by the combined approach | {$e['only_combined']} |";
        $md[] = "| Missed by both | {$e['missed']} |";
        $md[] = '';

        return implode("\n", $md);
    }

    /* -----------------------------------------------------------------
     | Shared Markdown sections
     |------------------------------------------------------------------*/

    private function markdownHeader(array $dataset): array
    {
        $md = ['# Attendance early warning: backtest', '', 'Generated '.now()->format('j F Y, H:i').' by `php artisan '.$this->commandLine().'`.', ''];

        if ($dataset['synthetic']) {
            $md[] = '> **Synthetic data.** These results come from a generated dataset of simulated students, not from real CGS records. They show how the method behaves on the patterns described below; they are not evidence of performance on real UTP students.';
            $md[] = '';
        }

        return $md;
    }

    /** The command as run, minus --output, so a reader can reproduce the report. */
    private function commandLine(): string
    {
        $parts = ['attendance:backtest'];
        if ($this->argument('file')) {
            $parts[] = basename((string) $this->argument('file'));
        }
        foreach (['synthetic', 'compare'] as $flag) {
            if ($this->option($flag)) {
                $parts[] = "--{$flag}";
            }
        }
        foreach (['cutoff', 'periods', 'students', 'seed', 'runs', 'min-periods-to-flag', 'active-until'] as $option) {
            $value = $this->option($option);
            $default = $this->getDefinition()->getOption($option)->getDefault();
            if ($value !== null && (string) $value !== (string) $default) {
                $parts[] = "--{$option}={$value}";
            }
        }

        return implode(' ', $parts);
    }

    private function patternsMarkdown(array $dataset): array
    {
        if (! $dataset['counts']) {
            return [];
        }

        $md = [
            'Synthetic patterns'.($dataset['seed'] !== null ? ' (students per pattern in each run)' : '').' (uniform noise of ±'.SyntheticAttendance::NOISE.' points per period, ±'.SyntheticAttendance::NOISY_FINE_NOISE.' for "noisy but fine"; '.SyntheticAttendance::SESSIONS_PER_PERIOD.' sessions a period):',
            '',
            '| Pattern | Students | Shape |',
            '|---|---|---|',
        ];
        foreach (SyntheticAttendance::PATTERNS as $key => $p) {
            $md[] = "| {$p['label']} | {$dataset['counts'][$key]} | {$p['shape']} |";
        }
        $md[] = '';

        return $md;
    }

    private function methodMarkdown(): array
    {
        $threshold = AttendanceRiskEvaluator::THRESHOLD;
        $minPeriods = (int) config('nureen.attendance.projection_min_periods', 3);

        return [
            '## Method',
            '',
            '- For each cut-off, every period after it is hidden. The system\'s checks are replayed as if they had run after each visible period, using the live code unchanged: the original rule (`AttendanceRiskEvaluator::ruleFlags()`: a period below '.$threshold.'%, or three declining periods ending at or below '.($threshold + AttendanceRiskEvaluator::EARLY_WARNING_BAND).'%) and the least-squares projection (`AttendanceProjector`, from period '.$minPeriods.' onwards), which may flag only inside the configured window (`AttendanceRiskEvaluator::projectionMayFlag()`).',
            '- **Truly at risk:** the student\'s actual final attendance over the whole semester is below '.$threshold.'%.',
            '- **Flagged by the cut-off:** the method flagged the student at any check up to and including the cut-off period (the live system emails on the first flag). The projection flags when the projected end-of-semester figure is below '.$threshold.'%. "Rule OR projection" is what the system uses.',
            '- **MAE / RMSE:** absolute and root-mean-squared difference, in percentage points, between the projection made at the cut-off and the actual final attendance, over students with a projection at that point. The **baseline** assumes the attendance so far simply continues. (The flagging window does not affect these.)',
            '- **Periods earlier:** for truly at-risk students flagged by both the combined approach and the rule alone by the cut-off, how many periods sooner the combined approach flagged them.',
        ];
    }
}
