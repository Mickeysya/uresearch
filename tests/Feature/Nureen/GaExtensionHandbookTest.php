<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\GaExtensionRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA Extension against the Postgraduate Handbook 2025, added to the
 * System checks (Support\GaExtensionRules):
 *   - eligibility: full-time research students only (warns);
 *   - the candidacy limit: no allowance beyond admission + max candidacy
 *     (warns);
 *   - PhD Stage Gate Level 3's two options, from Section C and the proofs;
 *   - config('nureen.ga_extension.allowed_levels') -- the one rule that
 *     blocks, and only when CGS narrows it.
 */
class GaExtensionHandbookTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

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
            'mode_of_study' => 'full_time',
            'study_type' => 'research',
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

    /* -----------------------------------------------------------------
     | Eligibility
     |------------------------------------------------------------------*/

    public function test_a_full_time_research_student_is_eligible_at_either_level(): void
    {
        foreach (['msc', 'phd'] as $level) {
            $check = $this->rules->eligibility($this->detail(['level_of_study' => $level]));
            $this->assertSame('pass', $check['status']);
            $this->assertSame('Full-time · Research.', $check['reason']);
        }
    }

    public function test_part_time_odl_or_coursework_is_flagged_but_not_blocked(): void
    {
        foreach ([['msc', 'part_time', 'research'], ['phd', 'full_time_odl', 'research'], ['phd', 'full_time', 'coursework']] as [$level, $mode, $type]) {
            $check = $this->rules->eligibility($this->detail(['level_of_study' => $level, 'mode_of_study' => $mode, 'study_type' => $type]));
            $this->assertSame('fail', $check['status'], "{$level} {$mode} {$type}");
            $this->assertStringContainsString('GA is for full-time research students only', $check['reason']);
        }

        $this->assertSame('unknown', $this->rules->eligibility($this->detail(['study_type' => null]))['status']);
    }

    public function test_an_ineligible_student_can_still_submit_and_cgs_sees_the_warning(): void
    {
        // Walks the 'cgs' chain (Admin -> Supervisor -> CGS, final), the
        // default until 2026-11-12 and still the chain of every application
        // submitted under it. The default 'ga_application' chain is
        // GaExtensionChainTest's.
        config(['nureen.ga_extension.final_approver' => 'cgs']);

        Storage::fake('local');
        Notification::fake();
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $this->approveGaApplication($student);

        $this->actingAs($student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['mode_of_study' => 'part_time']) + $this->gaExtensionDocuments())
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'ga_extension')->sole();
        $this->actingAs($this->admin())->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->actingAs($supervisor)->get(route('ga-extension.queue'))
            ->assertSee('Full-time research student')
            ->assertSee('Part-time · Research: GA is for full-time research students only');
    }

    /* -----------------------------------------------------------------
     | Candidacy limit
     |------------------------------------------------------------------*/

    public function test_an_msc_extension_inside_four_years_passes_and_past_it_fails(): void
    {
        $pass = $this->rules->candidacy($this->detail(['admission_date' => '2023-07-01', 'requested_new_end_date' => '2027-06-30']));
        $this->assertSame('pass', $pass['status'], $pass['reason']);
        $this->assertStringContainsString('Candidacy ends 30 Jun 2027 (MSc, 4 years from 1 Jul 2023)', $pass['reason']);

        $fail = $this->rules->candidacy($this->detail(['admission_date' => '2023-07-01', 'requested_new_end_date' => '2027-07-15']));
        $this->assertSame('fail', $fail['status']);
        $this->assertStringContainsString('No allowance is paid beyond candidacy', $fail['reason']);
    }

    public function test_a_phd_extension_inside_eight_years_passes_and_past_it_fails(): void
    {
        $this->assertSame('pass', $this->rules->candidacy($this->detail(['level_of_study' => 'phd', 'admission_date' => '2019-07-01', 'requested_new_end_date' => '2027-06-30']))['status']);

        $fail = $this->rules->candidacy($this->detail(['level_of_study' => 'phd', 'admission_date' => '2019-01-01', 'requested_new_end_date' => '2027-06-30']));
        $this->assertSame('fail', $fail['status']);
        $this->assertStringContainsString('Candidacy ends 31 Dec 2026 (PhD, 8 years', $fail['reason']);

        // The same request is fine for an MSc under its own limit? No: 4 years.
        $this->assertSame('fail', $this->rules->candidacy($this->detail(['admission_date' => '2019-01-01']))['status']);
        $this->assertSame('unknown', $this->rules->candidacy($this->detail(['admission_date' => null]))['status']);
    }

    public function test_the_candidacy_years_come_from_config(): void
    {
        config(['nureen.ga_extension.rules.max_candidacy_years.msc' => 3]);

        $this->assertSame('fail', $this->rules->candidacy($this->detail(['admission_date' => '2024-01-01', 'requested_new_end_date' => '2027-06-30']))['status']);
    }

    /* -----------------------------------------------------------------
     | PhD Stage Gate Level 3
     |------------------------------------------------------------------*/

    /** A PhD 32 months in at the 1 Jan 2027 start: Level 3. */
    protected function phdLevelThree(array $publications): GaExtensionDetail
    {
        return $this->detail(['level_of_study' => 'phd', 'admission_date' => '2024-05-01', 'publications' => $publications]);
    }

    protected function article(string $status, string $database, ?string $quartile = null, string $title = 'An article'): array
    {
        return ['type' => 'journal', 'status' => $status, 'title' => $title, 'authors' => 'A. Danial', 'year' => '2026', 'venue' => 'A Journal', 'database' => $database, 'quartile' => $quartile];
    }

    public function test_phd_level_three_option_one_a_published_q1_or_q2_wos_article(): void
    {
        $detail = $this->phdLevelThree([$this->article('published', 'wos', 'Q2', 'Deep sensing')]);

        $check = $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)'));

        $this->assertSame('pass', $check['status'], $check['reason']);
        $this->assertStringContainsString('Level 3, option 1: "Deep sensing" published in a Q2 WoS journal', $check['reason']);
    }

    public function test_phd_level_three_option_two_one_accepted_and_one_published(): void
    {
        $detail = $this->phdLevelThree([
            $this->article('accepted', 'scopus', 'Q3', 'Accepted one'),
            $this->article('published', 'era', null, 'Published one'),
        ]);

        $check = $this->rules->stageGate($detail, $this->documents('Proof of Journal Article Acceptance', 'Published Journal Article (front page)'));

        $this->assertSame('pass', $check['status'], $check['reason']);
        $this->assertStringContainsString('option 2: "Accepted one" accepted and "Published one" published, with both proofs attached', $check['reason']);
    }

    public function test_phd_level_three_says_what_each_option_is_missing(): void
    {
        // A Q3 Scopus article published, nothing accepted, only its proof attached.
        $detail = $this->phdLevelThree([$this->article('published', 'scopus', 'Q3')]);

        $check = $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)'));

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Option 1: no published Q1/Q2 WoS journal article in Section C', $check['reason']);
        $this->assertStringContainsString('Option 2: no accepted WoS/ERA/Scopus journal article in Section C; the acceptance proof is not attached', $check['reason']);
    }

    public function test_phd_level_three_needs_the_proofs_not_just_the_listing(): void
    {
        $detail = $this->phdLevelThree([$this->article('published', 'wos', 'Q1')]);

        $check = $this->rules->stageGate($detail, $this->documents());

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Option 1: the published article is not attached', $check['reason']);
    }

    public function test_conference_papers_and_rows_without_a_status_do_not_count(): void
    {
        $detail = $this->phdLevelThree([
            ['type' => 'conference', 'status' => 'published', 'title' => 'Talk', 'database' => 'wos', 'quartile' => 'Q1'],
            ['type' => 'journal', 'title' => 'Old row', 'database' => 'wos', 'quartile' => 'Q1'],
        ]);

        $check = $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)', 'Proof of Journal Article Acceptance'));

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('1 journal article has no accepted/published status', $check['reason']);
    }

    public function test_msc_level_three_is_unchanged_by_the_phd_options(): void
    {
        // MSc 20 months in: Level 3 needs the published article proof only.
        $detail = $this->detail(['admission_date' => '2025-05-01']);

        $this->assertSame('pass', $this->rules->stageGate($detail, $this->documents('Published Journal Article (front page)'))['status']);
        $this->assertSame('fail', $this->rules->stageGate($detail, $this->documents())['status']);
    }

    /* -----------------------------------------------------------------
     | allowed_levels
     |------------------------------------------------------------------*/

    protected function submitAs(string $level)
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $this->approveGaApplication($student);

        return [$student, $this->actingAs($student)->post(route('ga-extension.store'), $this->gaExtensionFields(['level_of_study' => $level]) + $this->gaExtensionDocuments())];
    }

    public function test_by_default_both_msc_and_phd_may_apply(): void
    {
        $this->assertSame(['msc', 'phd'], config('nureen.ga_extension.allowed_levels'));

        [, $response] = $this->submitAs('msc');
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_phd_only_refuses_an_msc_student_with_a_clear_message(): void
    {
        config(['nureen.ga_extension.allowed_levels' => ['phd']]);

        [$student, $response] = $this->submitAs('msc');

        $response->assertSessionHasErrors(['level_of_study' => 'GA extension is open to PhD students only. Contact CGS if you think this is wrong.']);
        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());

        // The form says so up front.
        $this->actingAs($student)->get(route('ga-extension.create'))
            ->assertSee('Open to PhD students only.')
            ->assertSee('<p class="message-warning" data-level-blocked hidden>', false);

        // A draft still saves, so nothing typed is lost.
        $this->actingAs($student)->post(route('ga-extension.draft.save'), ['level_of_study' => 'msc'])->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_DRAFT, Application::where('module_type', 'ga_extension')->sole()->status);
    }

    public function test_phd_only_still_accepts_a_phd_student(): void
    {
        config(['nureen.ga_extension.allowed_levels' => ['phd']]);

        [, $response] = $this->submitAs('phd');

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_the_form_shows_no_level_note_when_every_level_is_allowed(): void
    {
        $student = $this->student();
        $this->approveGaApplication($student);

        $this->actingAs($student)->get(route('ga-extension.create'))
            ->assertDontSee('<p class="message-warning" data-level-blocked hidden>', false)
            ->assertDontSee('students only.')
            ->assertSee('name="study_type"', false);
    }

    /* -----------------------------------------------------------------
     | Publications carry a status
     |------------------------------------------------------------------*/

    public function test_a_publication_row_needs_its_status_on_submit(): void
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $this->approveGaApplication($student);

        $this->actingAs($student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['publications' => [
                ['type' => 'journal', 'authors' => 'A', 'year' => '2026', 'title' => 'T', 'venue' => 'V'],
            ]]) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors('publications.0.status');

        $this->actingAs($student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['publications' => [
                ['type' => 'journal', 'status' => 'accepted', 'authors' => 'A', 'year' => '2026', 'title' => 'T', 'venue' => 'V'],
            ]]) + $this->gaExtensionDocuments() + ['acceptance_proof_documents' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', GaExtensionDetail::sole()->publications[0]['status']);
    }
}
