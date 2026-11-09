<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\ExpiryRule;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Chloe\Support\WorkstationTerminationTriggers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** One expiry rule everywhere: the expiry day itself is still valid; expired only from the day AFTER, on Kuala Lumpur calendar dates. */
class WorkstationExpiryDayTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function at(string $kualaLumpur): void
    {
        Carbon::setTestNow(Carbon::parse($kualaLumpur, 'Asia/Kuala_Lumpur'));
    }

    /** @return array<string, array{0: string, 1: bool}> now (Kuala Lumpur) => still valid, for an expiry date of 2026-11-09 */
    public static function boundary(): array
    {
        return [
            'expiry day minus 1 (23:59)' => ['2026-11-08 23:59:00', true],
            'expiry day, first minute' => ['2026-11-09 00:00:00', true],
            'expiry day, 10:30' => ['2026-11-09 10:30:00', true],
            'expiry day, last minute' => ['2026-11-09 23:59:59', true],
            'expiry day plus 1, first minute' => ['2026-11-10 00:00:00', false],
            'expiry day plus 1, noon' => ['2026-11-10 12:00:00', false],
        ];
    }

    /** @dataProvider boundary */
    public function test_the_rule_itself(string $now, bool $valid): void
    {
        $this->at($now);
        $expiry = Carbon::parse('2026-11-09');

        $this->assertSame($valid, ExpiryRule::isStillValid($expiry));
        $this->assertSame(! $valid, ExpiryRule::hasPassed($expiry));
    }

    public function test_a_missing_expiry_counts_as_expired_and_the_kuala_lumpur_day_decides_not_utc(): void
    {
        $this->assertTrue(ExpiryRule::hasPassed(null));

        // 17:00 UTC on 9 Nov is already 01:00 on 10 Nov in Kuala Lumpur
        Carbon::setTestNow(Carbon::parse('2026-11-09 17:00:00', 'UTC'));
        $this->assertTrue(ExpiryRule::hasPassed(Carbon::parse('2026-11-09')));
    }

    /** @dataProvider boundary */
    public function test_workstation_eligibility_follows_the_rule(string $now, bool $valid): void
    {
        $student = $this->eligibleStudent();
        StudyCandidacy::where('student_id', $student->id)->update(['candidacy_expiry_date' => '2026-11-09']);
        $this->at($now);

        $this->assertSame($valid, WorkstationEligibility::isEligible(StudentDirectory::fromUser($student->fresh())));
    }

    protected function seatHolder(string $expiry): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        StudyCandidacy::where('student_id', $student->id)->update(['candidacy_expiry_date' => $expiry]);
        $room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => 'male', 'name' => 'N2-02-01']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '5', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subYear()]);
    }

    /** @dataProvider boundary */
    public function test_the_automatic_termination_starts_only_the_day_after_the_expiry_day(string $now, bool $valid): void
    {
        $this->seatHolder('2026-11-09');
        $this->at($now);

        $triggers = WorkstationTerminationTriggers::findForOccupiedSeats();

        if ($valid) {
            $this->assertSame([], $triggers, 'no termination on or before the expiry day');
        } else {
            $this->assertCount(1, $triggers);
            $this->assertSame(WorkstationAllocator::REASON_TERMINATION_EXPIRY, $triggers[0]['reason']);
        }
    }

    public function test_the_workstation_answers_say_the_same_rule(): void
    {
        $text = '';
        foreach (['student/eligibility_loan_period.md', 'student/visibility_rules.md', 'student/student_system_guide.md', 'cgs/system_rules.md'] as $file) {
            $text .= file_get_contents(base_path('docs/knowledge/workstation/'.$file));
        }

        $this->assertStringNotContainsString('still in the future', $text);
        $this->assertStringContainsString('expiry day itself is still valid', $text);
    }
}
