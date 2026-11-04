<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Support\Eligibility\CandidacyPeriodSource;
use App\Modules\Hani\Support\Eligibility\EligibilityResult;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\FilesVivaNotices;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * "Within candidacy period" is checked by the system from the candidature
 * start date in the RPD masterlist (candidacies); the rest stays
 * self-declared. The AE's acknowledgement confirms the lot.
 */
class VivaEligibilityVerificationTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    /** Every self-declared MSc item, without the candidacy period. */
    protected const DECLARED = [
        'research_methodology_passed' => 1, 'publication_accepted' => 1,
        'registered_current_semester' => 1, 'no_outstanding_fees' => 1,
    ];

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing']);
    }

    protected function candidacy(string $type, string $start): void
    {
        DB::table('candidacies')->insert([
            'student_id' => $this->student->id,
            'programme_type' => $type,
            'candidature_start_date' => $start,
            'rpd_deadline' => now()->addYear()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_inside_the_period_is_verified_by_the_system_and_needs_no_tick(): void
    {
        $this->candidacy('msc_ft', now()->subMonths(30)->toDateString());

        $check = app(CandidacyPeriodSource::class)->check($this->student);
        $this->assertSame(EligibilityResult::VERIFIED, $check->status);
        $this->assertStringContainsString('48-month limit for MSc full-time', $check->message);

        $this->actingAs($this->student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('Verified by system')
            ->assertSee('Self-declared');

        // No within_candidacy_period tick posted: the system's result stands in.
        $this->actingAs($this->student)->post(route('viva.notice.store'), self::DECLARED + $this->noticeFields())
            ->assertSessionHasNoErrors();

        $record = VivaEligibility::sole();
        $this->assertTrue($record->within_candidacy_period);
        $this->assertSame(EligibilityResult::VERIFIED, $record->system_checks['within_candidacy_period']['status']);
        $this->assertSame(EligibilityResult::SELF_DECLARED, $record->system_checks['no_outstanding_fees']['status']);
    }

    public function test_outside_the_period_blocks_the_notice_whatever_was_ticked(): void
    {
        // The candidacy row's part-time wins over the profile's "Full-Time".
        $this->candidacy('msc_pt', now()->subMonths(73)->toDateString());

        $this->actingAs($this->student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('Not met (system check)')
            ->assertSee('72-month limit for MSc part-time ended');

        $this->actingAs($this->student)->post(route('viva.notice.store'),
            self::DECLARED + ['within_candidacy_period' => 1] + $this->noticeFields())
            ->assertRedirect(route('viva.eligibility'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ended'));

        $this->assertSame(0, Application::count());
    }

    public function test_no_candidacy_row_leaves_the_item_self_declared(): void
    {
        $this->assertSame(EligibilityResult::SELF_DECLARED, app(CandidacyPeriodSource::class)->check($this->student)->status);

        // Without the tick: refused.
        $this->actingAs($this->student)->post(route('viva.notice.store'), self::DECLARED + $this->noticeFields())
            ->assertSessionHas('error');
        $this->assertSame(0, Application::count());

        // With it: filed, and recorded as self-declared.
        $this->actingAs($this->student)->post(route('viva.notice.store'),
            self::DECLARED + ['within_candidacy_period' => 1] + $this->noticeFields())
            ->assertSessionHasNoErrors();
        $this->assertSame(EligibilityResult::SELF_DECLARED, VivaEligibility::sole()->system_checks['within_candidacy_period']['status']);
    }

    public function test_a_system_failure_blocks_with_a_reason(): void
    {
        $this->app->bind(CandidacyPeriodSource::class, fn () => new class extends CandidacyPeriodSource {
            public function check(User $student): EligibilityResult
            {
                return new EligibilityResult(EligibilityResult::FAILED, 'The candidacy period could not be checked automatically just now.');
            }
        });

        $this->assertFalse(VivaChecklist::isEligible($this->student));

        $this->actingAs($this->student)->post(route('viva.notice.store'),
            self::DECLARED + ['within_candidacy_period' => 1] + $this->noticeFields())
            ->assertSessionHas('error', 'The candidacy period could not be checked automatically just now.');
        $this->assertSame(0, Application::count());
    }

    public function test_the_ae_sees_the_summary_and_acknowledging_confirms_it(): void
    {
        $this->candidacy('msc_ft', now()->subMonths(12)->toDateString());
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        $this->actingAs($this->student)->post(route('viva.notice.store'), self::DECLARED + $this->noticeFields());
        $notice = Application::sole();

        $this->actingAs($ae)->get(route('viva.queue'))
            ->assertOk()
            ->assertSee('Verified by system')
            ->assertSee('Acknowledging confirms these.');

        $this->actingAs($ae)->post(route('viva.decide', $notice), ['decision' => 'approve'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'eligibility confirmed'));

        $record = VivaEligibility::sole();
        $this->assertSame($ae->id, $record->verified_by_id);
        $this->assertNotNull($record->verified_at);
    }
}
