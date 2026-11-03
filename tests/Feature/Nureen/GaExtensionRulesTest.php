<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\GaExtensionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The GA Extension Form's Terms and Conditions as checks
 * (Support\GaExtensionRules), each passing and failing for an MSc and a PhD
 * student, and the stage gate level at its boundary months. Every number
 * is config('nureen.ga_extension.rules'); one test changes them.
 *
 * Plain models, nothing saved: the checks read only what they are given.
 */
class GaExtensionRulesTest extends TestCase
{
    protected GaExtensionRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));
        $this->rules = new GaExtensionRules;
    }

    protected function detail(array $attributes): GaExtensionDetail
    {
        return (new GaExtensionDetail)->forceFill($attributes + [
            'level_of_study' => 'msc',
            'admission_date' => '2025-09-01',
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
        ]);
    }

    /** @return Collection<int, ApplicationDocument> */
    protected function documents(string ...$types): Collection
    {
        return collect($types)->map(fn ($type) => (new ApplicationDocument)->forceFill(['doc_type' => $type]));
    }

    protected function application(?string $submittedAt = '2026-11-03'): Application
    {
        return (new Application)->forceFill(['submitted_at' => $submittedAt ? Carbon::parse($submittedAt) : null]);
    }

    /* -----------------------------------------------------------------
     | (iii) Total duration from admission
     |------------------------------------------------------------------*/

    public function test_msc_total_duration_passes_at_24_months_and_fails_past_it(): void
    {
        $pass = $this->rules->totalDuration($this->detail(['admission_date' => '2025-07-01', 'requested_new_end_date' => '2027-06-30']));
        $this->assertSame('pass', $pass['status'], $pass['reason']);
        $this->assertStringContainsString('24 months from admission', $pass['reason']);

        $fail = $this->rules->totalDuration($this->detail(['admission_date' => '2025-07-01', 'requested_new_end_date' => '2027-07-31']));
        $this->assertSame('fail', $fail['status']);
        $this->assertStringContainsString('25 months', $fail['reason']);
        $this->assertStringContainsString('MSc maximum of 24 months', $fail['reason']);
    }

    public function test_phd_total_duration_passes_at_42_months_and_fails_past_it(): void
    {
        $pass = $this->rules->totalDuration($this->detail(['level_of_study' => 'phd', 'admission_date' => '2024-01-01', 'requested_new_end_date' => '2027-06-30']));
        $this->assertSame('pass', $pass['status'], $pass['reason']);
        $this->assertStringContainsString('42 months', $pass['reason']);

        $fail = $this->rules->totalDuration($this->detail(['level_of_study' => 'phd', 'admission_date' => '2024-01-01', 'requested_new_end_date' => '2027-07-15']));
        $this->assertSame('fail', $fail['status']);
        $this->assertStringContainsString('PhD maximum of 42 months', $fail['reason']);
    }

    /* -----------------------------------------------------------------
     | (iii) Extension length
     |------------------------------------------------------------------*/

    public function test_extension_length_between_3_and_12_months(): void
    {
        foreach (['msc', 'phd'] as $level) {
            $cases = [
                ['2027-03-31', 'pass'],  // 3 months
                ['2027-12-31', 'pass'],  // 12 months
                ['2027-02-28', 'fail'],  // 2 months
                ['2028-01-31', 'fail'],  // 13 months
            ];

            foreach ($cases as [$end, $expected]) {
                $check = $this->rules->extensionLength($this->detail(['level_of_study' => $level, 'current_end_date' => '2026-12-31', 'requested_new_end_date' => $end]));
                $this->assertSame($expected, $check['status'], "{$level} to {$end}: {$check['reason']}");
            }
        }

        $half = $this->rules->extensionLength($this->detail(['current_end_date' => '2026-12-15', 'requested_new_end_date' => '2027-06-30']));
        $this->assertStringContainsString('6.5 months', $half['reason']);
    }

    /* -----------------------------------------------------------------
     | (iii) Start and end days
     |------------------------------------------------------------------*/

    public function test_the_appointment_starts_on_the_1st_or_16th(): void
    {
        foreach (['msc', 'phd'] as $level) {
            $this->assertSame('pass', $this->rules->startDay($this->detail(['level_of_study' => $level, 'current_end_date' => '2026-12-31']))['status']);
            $this->assertSame('pass', $this->rules->startDay($this->detail(['level_of_study' => $level, 'current_end_date' => '2026-12-15']))['status']);

            $fail = $this->rules->startDay($this->detail(['level_of_study' => $level, 'current_end_date' => '2026-12-20']));
            $this->assertSame('fail', $fail['status']);
            $this->assertStringContainsString('Starts 21 Dec 2026', $fail['reason']);
            $this->assertStringContainsString('1st or 16th', $fail['reason']);
        }
    }

    public function test_the_appointment_ends_on_the_15th_or_the_last_day(): void
    {
        foreach (['msc', 'phd'] as $level) {
            foreach (['2027-06-15', '2027-06-30', '2028-02-29'] as $end) {
                $this->assertSame('pass', $this->rules->endDay($this->detail(['level_of_study' => $level, 'requested_new_end_date' => $end]))['status'], $end);
            }

            $fail = $this->rules->endDay($this->detail(['level_of_study' => $level, 'requested_new_end_date' => '2027-06-29']));
            $this->assertSame('fail', $fail['status']);
            $this->assertStringContainsString('15th or last day of the month', $fail['reason']);
        }
    }

    /* -----------------------------------------------------------------
     | (iii) One month's notice
     |------------------------------------------------------------------*/

    public function test_it_is_submitted_at_least_a_month_before_the_start(): void
    {
        foreach (['msc', 'phd'] as $level) {
            $detail = $this->detail(['level_of_study' => $level, 'current_end_date' => '2026-12-31']);

            $this->assertSame('pass', $this->rules->notice($detail, $this->application('2026-12-01'))['status']);
            $this->assertSame('pass', $this->rules->notice($detail, $this->application('2026-11-03'))['status']);

            $fail = $this->rules->notice($detail, $this->application('2026-12-10'));
            $this->assertSame('fail', $fail['status']);
            $this->assertStringContainsString('by 1 Dec 2026', $fail['reason']);
        }

        // A draft is judged as if submitted today.
        $draft = $this->rules->notice($this->detail(['current_end_date' => '2026-11-20']), $this->application(null));
        $this->assertSame('fail', $draft['status']);
        $this->assertStringContainsString('Not yet submitted', $draft['reason']);
    }

    /* -----------------------------------------------------------------
     | (vii)/(viii) Stage gates
     |------------------------------------------------------------------*/

    public function test_the_stage_gate_level_at_boundary_months(): void
    {
        $expected = [
            'msc' => [0 => 0, 2 => 0, 3 => 1, 11 => 1, 12 => 2, 17 => 2, 18 => 3, 23 => 3, 24 => 3, 30 => 3],
            'phd' => [0 => 0, 5 => 0, 6 => 1, 17 => 1, 18 => 2, 29 => 2, 30 => 3, 35 => 3, 36 => 4, 41 => 4, 42 => 4],
        ];

        foreach ($expected as $level => $byMonth) {
            foreach ($byMonth as $months => $gate) {
                $this->assertSame($gate, GaExtensionRules::stageGateLevel($level, $months), "{$level} at {$months} months");
            }
        }
    }

    public function test_months_since_admission_count_whole_months_to_the_start(): void
    {
        $admission = Carbon::parse('2025-09-01');

        $this->assertSame(12, GaExtensionRules::monthsCompleted($admission, Carbon::parse('2026-09-01')));
        $this->assertSame(11, GaExtensionRules::monthsCompleted($admission, Carbon::parse('2026-08-31')));
        $this->assertSame(0, GaExtensionRules::monthsCompleted($admission, Carbon::parse('2025-08-01')));

        // Through the check: admission 1 Sep 2025, current end 31 Aug 2026
        // -> starts 1 Sep 2026, 12 months -> MSc Level 2.
        $check = $this->rules->stageGate($this->detail(['current_end_date' => '2026-08-31']), $this->documents());
        $this->assertStringContainsString('12 months since admission', $check['reason']);
        $this->assertStringContainsString('Level 2', $check['reason']);
    }

    public function test_msc_stage_gate_proof(): void
    {
        // 14 months in: Level 2, acceptance needed.
        $detail = $this->detail(['admission_date' => '2025-11-01', 'current_end_date' => '2026-12-31']);
        $this->assertSame('pass', $this->rules->stageGate($detail, $this->documents('Proof of Journal Article Acceptance'))['status']);
        $fail = $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)', 'Proof of Soft Bound Submission'));
        $this->assertSame('fail', $fail['status']);
        $this->assertStringContainsString('"Proof of Journal Article Acceptance" is not attached', $fail['reason']);

        // 20 months in: Level 3, a published article.
        $detail = $this->detail(['admission_date' => '2025-05-01', 'current_end_date' => '2026-12-31']);
        $this->assertSame('pass', $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)'))['status']);
        $this->assertSame('fail', $this->rules->stageGate($detail, $this->documents('Proof of Journal Article Acceptance'))['status']);

        // 4 months in: Level 1 (RPD), nothing to attach.
        $detail = $this->detail(['admission_date' => '2026-09-01', 'current_end_date' => '2026-12-31']);
        $level1 = $this->rules->stageGate($detail, $this->documents());
        $this->assertSame('pass', $level1['status']);
        $this->assertStringContainsString('Level 1 (RPD completion)', $level1['reason']);

        // 1 month in: before any gate.
        $detail = $this->detail(['admission_date' => '2026-12-01', 'current_end_date' => '2026-12-31']);
        $this->assertStringContainsString('before the first stage gate', $this->rules->stageGate($detail, $this->documents())['reason']);
    }

    public function test_phd_stage_gate_proof(): void
    {
        // 37 months in: Level 4, soft bound submission.
        $detail = $this->detail(['level_of_study' => 'phd', 'admission_date' => '2023-12-01', 'current_end_date' => '2026-12-31']);
        $pass = $this->rules->stageGate($detail, $this->documents('Proof of Soft Bound Submission'));
        $this->assertSame('pass', $pass['status']);
        $this->assertStringContainsString('Level 4', $pass['reason']);
        $this->assertSame('fail', $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)'))['status']);

        // 20 months in: Level 2, acceptance.
        $detail = $this->detail(['level_of_study' => 'phd', 'admission_date' => '2025-05-01', 'current_end_date' => '2026-12-31']);
        $this->assertSame('pass', $this->rules->stageGate($detail, $this->documents('Proof of Journal Article Acceptance'))['status']);
        $this->assertSame('fail', $this->rules->stageGate($detail, $this->documents())['status']);
    }

    public function test_the_old_combined_proof_counts_for_levels_two_and_three_but_not_four(): void
    {
        $legacy = $this->documents('Acceptance Letter or Published Paper');

        $msc = $this->detail(['admission_date' => '2025-05-01', 'current_end_date' => '2026-12-31']);
        $check = $this->rules->stageGate($msc, $legacy);
        $this->assertSame('pass', $check['status']);
        $this->assertStringContainsString('check it is the right one', $check['reason']);

        $phd4 = $this->detail(['level_of_study' => 'phd', 'admission_date' => '2023-12-01', 'current_end_date' => '2026-12-31']);
        $this->assertSame('fail', $this->rules->stageGate($phd4, $legacy)['status']);
    }

    /* -----------------------------------------------------------------
     | Missing data, and config
     |------------------------------------------------------------------*/

    public function test_a_check_without_its_data_is_unknown_not_failed(): void
    {
        $empty = (new GaExtensionDetail)->forceFill([]);

        foreach ($this->rules->check($empty, $this->application(), $this->documents()) as $check) {
            $this->assertSame('unknown', $check['status'], $check['key']);
        }
    }

    public function test_every_number_comes_from_config(): void
    {
        config([
            'nureen.ga_extension.rules.max_months.msc' => 30,
            'nureen.ga_extension.rules.extension_months' => ['min' => 1, 'max' => 2],
            'nureen.ga_extension.rules.start_days' => [1],
            'nureen.ga_extension.rules.notice_months' => 2,
            'nureen.ga_extension.rules.stage_gates.msc.2.from' => 14,
        ]);

        $this->assertSame('pass', $this->rules->totalDuration($this->detail(['admission_date' => '2025-07-01', 'requested_new_end_date' => '2027-07-31']))['status']);
        $this->assertSame('fail', $this->rules->extensionLength($this->detail([]))['status']);
        $this->assertSame('fail', $this->rules->startDay($this->detail(['current_end_date' => '2026-12-15']))['status']);
        $this->assertSame('fail', $this->rules->notice($this->detail([]), $this->application('2026-11-15'))['status']);
        $this->assertSame(1, GaExtensionRules::stageGateLevel('msc', 13));
    }
}
