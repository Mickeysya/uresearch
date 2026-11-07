<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationReturned;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Http\Controllers\ExaminerSubstitutionController;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Models\ExaminerSubstitutionRequest;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Notifications\SubstitutionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Conflict Detection acting on what it flags: links to each list, asking a
 * department to substitute, and merging two records of one person.
 */
class ConflictDetectionActionsTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected Examiner $shared;

    protected User $senior;

    protected User $director;

    protected User $dean;

    /** @var array<string, array{supervisor: User, student: User, ae: User, internal: Examiner}> */
    protected array $cast = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shared = $this->external('Assoc Prof Dr Melissa Teoh', 'melissa.teoh@example.org');
        $this->senior = $this->seniorExec();
        $this->director = $this->seniorDirector();
        $this->dean = $this->dean();

        foreach (['Computing' => 'c', 'Petroleum Engineering' => 'p'] as $department => $k) {
            $supervisor = $this->supervisor("sup.{$k}@test.my");
            $this->cast[$k] = [
                'supervisor' => $supervisor,
                'student' => $this->student("2200000{$k}", "student.{$k}@test.my", [
                    'name' => "Candidate {$department}", 'supervisor_id' => $supervisor->id, 'department' => $department,
                ]),
                'ae' => $this->user(Role::ACADEMIC_EXEC, ['email' => "ae.{$k}@test.my", 'name' => "AE {$department}", 'department' => $department]),
                'internal' => Examiner::create([
                    'name' => "Dr Internal {$department}", 'email' => "internal.{$k}@example.com", 'department' => $department,
                    'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
                ]),
            ];
        }
    }

    protected function external(string $name, ?string $email): Examiner
    {
        return Examiner::create([
            'name' => $name, 'email' => $email, 'department' => 'External', 'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Universiti Malaya (UM)', 'is_active' => true,
        ]);
    }

    protected function busyExaminer(): Examiner
    {
        $examiner = $this->external('Dr Busy', 'busy@example.org');
        $examiner->update(['assigned_until' => now()->addMonth()]);

        return $examiner;
    }

    /** Files one department's panel at the first stage. */
    protected function nominate(string $k, ?Examiner $external = null, array $seats = []): Application
    {
        $this->actingAs($this->cast[$k]['supervisor'])->post(route('examiner-nomination.store'), $seats + [
            'student_id' => $this->cast[$k]['student']->id,
            'thesis_title' => 'Anomaly detection in pipelines',
            'internal_main_id' => $this->cast[$k]['internal']->id,
            'external_main_id' => ($external ?? $this->shared)->id,
        ])->assertSessionHasNoErrors();

        return Application::where('student_id', $this->cast[$k]['student']->id)->latest('id')->firstOrFail();
    }

    /** Walks a list forward to the named stage, or to release if none is given. */
    protected function advance(Application $application, ?string $to = null): Application
    {
        $k = $application->student->department === 'Computing' ? 'c' : 'p';
        $actors = ['academic_exec' => $this->cast[$k]['ae'], 'cgs_compile' => $this->senior, 'senior_director' => $this->director, 'dean' => $this->dean, 'cgs_final' => $this->senior];

        while (($application = $application->fresh())->status === Application::STATUS_PENDING && $application->current_stage !== $to) {
            $this->actingAs($actors[$application->current_stage])
                ->post(route('examiner-nomination.decide', $application), ['decision' => 'approve'])
                ->assertSessionHas('status');
        }

        return $application;
    }

    protected function nominationOf(Application $application): ExaminerNomination
    {
        return ExaminerNomination::where('application_id', $application->id)->sole();
    }

    protected function ask(User $user, Application $application, string $reason = 'Already examining for Petroleum.')
    {
        return $this->actingAs($user)->from(route('conflict-detection.index'))
            ->post(route('examiner-substitution.store', $this->nominationOf($application)), ['seat' => 'external_main_id', 'reason' => $reason]);
    }

    protected function merge(Examiner $keep, Examiner $other, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->senior)->from(route('conflict-detection.index'))
            ->post(route('conflict-detection.merge'), ['record_a_id' => $keep->id, 'record_b_id' => $other->id, 'keep_id' => $keep->id]);
    }

    /** Two records of one person, each on a different department's list. */
    protected function twoRecords(): array
    {
        $a = $this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org');
        $b = $this->external('Hassan Omar, PhD', 'h.omar@example.org');

        return [$a, $b, $this->nominate('c', $a), $this->nominate('p', $b)];
    }

    // ---- Links ----------------------------------------------------------

    public function test_each_number_links_to_the_card_the_viewer_can_open(): void
    {
        $computing = $this->nominate('c');
        $petroleum = $this->nominate('p');

        // Computing's AE holds their own list; Petroleum's is not theirs to open.
        $this->actingAs($this->cast['c']['ae'])->get(route('conflict-detection.index'))
            ->assertSee(route('examiner-nomination.queue', ['stage' => 'academic_exec', 'q' => $computing->id]))
            ->assertDontSee('q='.$petroleum->id)
            ->assertDontSee(route('examiner-nomination.report').'#nomination-'.$this->nominationOf($petroleum)->id)
            ->assertSee('#'.$petroleum->id);

        // Neither is at the Senior Executive's stage yet: the report row.
        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertSee(route('examiner-nomination.report').'#nomination-'.$this->nominationOf($petroleum)->id);

        $this->advance($computing, 'cgs_compile');

        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertSee(route('examiner-nomination.queue', ['stage' => 'cgs_compile', 'q' => $computing->id]));

        // The link lands on that one card.
        $this->actingAs($this->senior)->get(route('examiner-nomination.queue', ['stage' => 'cgs_compile', 'q' => $computing->id]))
            ->assertOk()
            ->assertSee('queue-row-id">#'.$computing->id, false);
    }

    public function test_the_released_list_keeps_the_examiner_and_the_pending_one_is_asked(): void
    {
        // Filed before either is released: afterwards the examiner is Assigned.
        $computing = $this->nominate('c');
        $pending = $this->advance($this->nominate('p'), 'cgs_compile');
        $released = $this->advance($computing);
        $panel = $this->nominationOf($released);

        $this->assertSame(Application::STATUS_APPROVED, $released->status);

        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertSeeInOrder(['#'.$released->id, 'Released: keeps the examiner', 'Manage panel → Replace'])
            ->assertSee(route('examiner-panel.show', $panel).'#seat-external_main_id')
            ->assertSee(route('examiner-substitution.store', $this->nominationOf($pending)))
            ->assertDontSee(route('examiner-substitution.store', $panel));

        // The anchor is on the panel page.
        $this->actingAs($this->senior)->get(route('examiner-panel.show', $panel))
            ->assertSee('id="seat-external_main_id"', false);
    }

    public function test_two_released_lists_are_not_told_one_keeps_the_examiner(): void
    {
        $computing = $this->nominate('c');
        $petroleum = $this->nominate('p');
        $this->advance($computing);
        $this->advance($petroleum);

        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertDontSee('keeps the examiner')
            ->assertSee('Manage panel → Replace');
    }

    public function test_every_link_on_the_page_leaves_outside_the_region_swap(): void
    {
        [, , $one] = $this->twoRecords();
        $this->advance($one, 'cgs_compile');
        $application = $this->advance($this->nominate('c'));
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        $page = new \DOMDocument;
        @$page->loadHTML($this->actingAs($this->senior)->get(route('conflict-detection.index'))->getContent());
        $links = (new \DOMXPath($page))->query("//div[contains(@class, 'card-wide')]//a");

        $this->assertGreaterThan(2, $links->length);

        foreach ($links as $link) {
            $this->assertTrue($link->hasAttribute('data-region-swap-ignore'), $link->getAttribute('href').' lacks data-region-swap-ignore');
        }
    }

    // ---- Asking at cgs_compile ------------------------------------------

    public function test_the_senior_executive_asks_at_compile_and_the_department_is_told(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'cgs_compile');

        $this->ask($this->senior, $application, '')->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('examiner_substitution_requests', 0);

        $this->ask($this->senior, $application)->assertSessionHas('status');

        // The list stays where it is.
        $this->assertSame('cgs_compile', $application->fresh()->current_stage);
        $this->assertDatabaseHas('examiner_substitution_requests', [
            'nomination_id' => $this->nominationOf($application)->id, 'seat' => 'external_main_id',
            'examiner_id' => $this->shared->id, 'status' => 'open', 'requested_by_id' => $this->senior->id,
        ]);
        $this->assertDatabaseHas('examiner_seat_history', ['event' => 'sub_requested', 'examiner_id' => $this->shared->id]);

        $request = ExaminerSubstitutionRequest::sole();

        Notification::assertSentTo($this->cast['p']['ae'], SubstitutionRequested::class, function ($n) use ($request) {
            $mail = $n->toMail($this->cast['p']['ae'])->render()->toHtml();

            return str_contains($mail, route('examiner-substitution.show', $request)) && str_contains($mail, 'Already examining for Petroleum.');
        });
        Notification::assertSentTo($this->cast['p']['supervisor'], SubstitutionRequested::class);
        Notification::assertNotSentTo($this->cast['c']['ae'], SubstitutionRequested::class);
        Notification::assertNotSentTo($this->cast['p']['student'], SubstitutionRequested::class);

        // Once per seat; the row now says so and offers no second form.
        $this->ask($this->senior, $application)->assertSessionHas('error');
        $this->assertDatabaseCount('examiner_substitution_requests', 1);

        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertSee('Substitution requested')
            ->assertSee('Already examining for Petroleum.');

        // The holder's card says so too.
        $this->actingAs($this->senior)->get(route('examiner-nomination.queue', ['stage' => 'cgs_compile']))
            ->assertSee('Substitution requested: External (main)');
    }

    public function test_only_the_person_holding_the_list_can_ask_and_only_about_a_real_clash(): void
    {
        $this->nominate('c');
        $application = $this->nominate('p');

        // At the department: not yet the Senior Executive's to ask.
        $this->ask($this->senior, $application)->assertSessionHas('error');

        $this->advance($application, 'senior_director');
        $this->ask($this->senior, $application)->assertSessionHas('error');
        $this->assertDatabaseCount('examiner_substitution_requests', 0);

        // The AE has no such route.
        $this->ask($this->cast['p']['ae'], $application)->assertForbidden();

        // No clash on this seat: nothing to substitute.
        $this->actingAs($this->director)->from(route('conflict-detection.index'))
            ->post(route('examiner-substitution.store', $this->nominationOf($application)), ['seat' => 'internal_main_id', 'reason' => 'No clash here.'])
            ->assertSessionHas('error');
        $this->assertDatabaseCount('examiner_substitution_requests', 0);
    }

    // ---- The department answers -----------------------------------------

    public function test_the_department_replaces_the_seat_from_suggestions(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'cgs_compile');
        $this->ask($this->senior, $application);
        $request = ExaminerSubstitutionRequest::sole();

        $replacement = $this->external('Dr Farah Anomaly', 'farah@example.org');
        $replacement->expertiseKeywords()->create(['keyword' => 'anomaly detection']);

        // Both the AE and the supervisor can answer, and see the suggestion.
        foreach (['ae', 'supervisor'] as $who) {
            $this->actingAs($this->cast['p'][$who])->get(route('examiner-substitution.show', $request))
                ->assertOk()
                ->assertSee('Already examining for Petroleum.')
                ->assertSee('Suggested from the eligible pool')
                ->assertSee('<b>Dr Farah Anomaly</b>', false);
        }

        $this->actingAs($this->cast['p']['supervisor'])->post(route('examiner-substitution.replace', $request), ['examiner_id' => $replacement->id])
            ->assertRedirect(route('examiner-substitution.show', $request))
            ->assertSessionHas('status');

        $this->assertSame($replacement->id, $this->nominationOf($application)->external_main_id);
        $this->assertSame('cgs_compile', $application->fresh()->current_stage);
        $this->assertDatabaseHas('examiner_substitution_requests', ['id' => $request->id, 'status' => 'done', 'replacement_examiner_id' => $replacement->id]);
        $this->assertDatabaseHas('examiner_seat_history', ['event' => 'substituted', 'examiner_id' => $replacement->id, 'seat' => 'external_main_id']);

        // Nobody is tied up by a pending list, and the clash has gone.
        $this->assertNull($replacement->fresh()->assigned_until);
        $this->actingAs($this->senior)->get(route('conflict-detection.index'))->assertDontSee('named by more than one department');

        // Answered once.
        $this->actingAs($this->cast['p']['ae'])->post(route('examiner-substitution.replace', $request), ['examiner_id' => $this->external('Dr Other', 'other@example.org')->id])
            ->assertSessionHasErrors('examiner_id');
        $this->assertSame($replacement->id, $this->nominationOf($application)->external_main_id);
    }

    public function test_the_page_belongs_to_the_candidates_department_alone(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'cgs_compile');
        $this->ask($this->senior, $application);
        $request = ExaminerSubstitutionRequest::sole();

        $this->actingAs($this->cast['c']['ae'])->get(route('examiner-substitution.show', $request))->assertForbidden();
        $this->actingAs($this->cast['c']['supervisor'])->get(route('examiner-substitution.show', $request))->assertForbidden();
        $this->actingAs($this->cast['p']['student'])->get(route('examiner-substitution.show', $request))->assertForbidden();
        $this->actingAs($this->senior)->get(route('examiner-substitution.show', $request))->assertForbidden();

        // Another department's AE sees that a request exists, not its reason.
        $this->actingAs($this->cast['c']['ae'])->get(route('conflict-detection.index'))
            ->assertSee('Substitution requested')
            ->assertDontSee('Already examining for Petroleum.')
            ->assertDontSee(route('examiner-substitution.show', $request));
    }

    public function test_a_replacement_must_pass_the_nomination_rules(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'cgs_compile');
        $this->ask($this->senior, $application);
        $request = ExaminerSubstitutionRequest::sole();
        $ae = $this->cast['p']['ae'];

        // Wrong type, and not eligible.
        $this->actingAs($ae)->post(route('examiner-substitution.replace', $request), ['examiner_id' => $this->cast['p']['internal']->id])
            ->assertSessionHasErrors('examiner_id');
        $this->actingAs($ae)->post(route('examiner-substitution.replace', $request), ['examiner_id' => $this->busyExaminer()->id])
            ->assertSessionHasErrors('examiner_id');

        $this->assertSame($this->shared->id, $this->nominationOf($application)->external_main_id);
    }

    public function test_a_seat_cannot_change_under_a_signature_further_up_the_chain(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'cgs_compile');
        $this->ask($this->senior, $application);
        $request = ExaminerSubstitutionRequest::sole();

        $this->advance($application, 'senior_director');

        $this->actingAs($this->cast['p']['ae'])->get(route('examiner-substitution.show', $request))
            ->assertOk()->assertSee('cannot be changed here any more');
        $this->actingAs($this->cast['p']['ae'])->post(route('examiner-substitution.replace', $request), ['examiner_id' => $this->external('Dr Late', 'late@example.org')->id])
            ->assertSessionHasErrors('examiner_id');
        $this->assertSame($this->shared->id, $this->nominationOf($application)->external_main_id);
    }

    // ---- Asking from the Senior Director or the Dean ---------------------

    public function test_the_director_and_dean_reach_the_page_and_their_ask_sends_the_list_back(): void
    {
        Notification::fake();
        $this->nominate('c');
        $application = $this->advance($this->nominate('p'), 'dean');
        $panel = $this->nominationOf($application);

        $this->actingAs($this->director)->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertDontSee(route('examiner-substitution.store', $panel))
            ->assertSee('who can send it back');

        $this->actingAs($this->dean)->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertSee(route('examiner-substitution.store', $panel))
            ->assertSee('Conflict Detection');

        $reason = 'Teoh is already held for Computing.';
        $this->ask($this->dean, $application, $reason)->assertSessionHas('status');

        // Through the engine: the same return as the queue's button.
        $this->assertSame('academic_exec', $application->fresh()->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);
        $this->assertDatabaseHas('approval_history', ['application_id' => $application->id, 'decision' => 'returned', 'stage_key' => 'dean']);

        // The candidate hears a list went back, never who or why.
        Notification::assertSentTo($this->cast['p']['student'], ApplicationReturned::class, function ($n) use ($reason) {
            $said = json_encode($n->toArray($this->cast['p']['student']));

            return ! str_contains($said, 'Teoh') && ! str_contains($said, $reason)
                && str_contains($said, ExaminerSubstitutionController::RETURN_REMARKS);
        });
        $this->assertDatabaseMissing('approval_history', ['application_id' => $application->id, 'remarks' => $reason]);

        Notification::assertSentTo($this->cast['p']['ae'], SubstitutionRequested::class);
        Notification::assertSentTo($this->cast['p']['supervisor'], SubstitutionRequested::class);

        // And the returned list now has a way to change the seat.
        $replacement = $this->external('Dr Returned', 'returned@example.org');
        $this->actingAs($this->cast['p']['ae'])->post(route('examiner-substitution.replace', ExaminerSubstitutionRequest::sole()), ['examiner_id' => $replacement->id])
            ->assertSessionHas('status');
        $this->assertSame($replacement->id, $panel->fresh()->external_main_id);
    }

    public function test_non_executive_cgs_and_students_stay_off_the_page(): void
    {
        $this->actingAs($this->cgs())->get(route('conflict-detection.index'))->assertForbidden();
        $this->actingAs($this->cast['c']['student'])->get(route('conflict-detection.index'))->assertForbidden();
    }

    // ---- Possibly the same person ---------------------------------------

    public function test_duplicates_are_one_line_per_pair_listing_the_applications(): void
    {
        [$a, $b, $one, $two] = $this->twoRecords();

        $response = $this->actingAs($this->senior)->get(route('conflict-detection.index'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'class="cd-pair"'));
        $response->assertSeeInOrder(['Prof Dr Hassan bin Omar', 'Hassan Omar, PhD', '#'.$one->id, '#'.$two->id])
            ->assertSee('Not the same person')
            ->assertSee('Same person: merge');

        // The Senior Executive's alone.
        $this->actingAs($this->dean)->get(route('conflict-detection.index'))
            ->assertDontSee('Possibly the same person');
    }

    public function test_not_the_same_person_clears_the_pair(): void
    {
        [$a, $b] = $this->twoRecords();

        $this->actingAs($this->senior)->from(route('conflict-detection.index'))
            ->post(route('examiner-admin.dismiss-duplicate'), ['examiner_id' => $a->id, 'other_id' => $b->id])
            ->assertRedirect(route('conflict-detection.index'));

        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertDontSee('Possibly the same person');
    }

    public function test_merging_moves_the_seats_marks_the_other_unavailable_and_deletes_nothing(): void
    {
        [$keep, $other, $one, $two] = $this->twoRecords();
        $keep->expertiseKeywords()->create(['keyword' => 'corrosion']);
        $other->expertiseKeywords()->create(['keyword' => 'corrosion']);
        $other->expertiseKeywords()->create(['keyword' => 'pipelines']);
        $keep->publications()->create(['title' => 'Shared Paper', 'year' => 2020]);
        $other->publications()->create(['title' => 'shared paper ', 'year' => 2020]);
        $other->publications()->create(['title' => 'Second Paper', 'year' => 2021, 'coauthors' => 'A. Lee']);
        $count = Examiner::count();

        $this->merge($keep, $other)->assertRedirect(route('conflict-detection.index'))->assertSessionHas('status');

        // The seat column moved, the kept record is on both lists, and that is
        // now a real clash the page can act on.
        $this->assertSame($keep->id, $this->nominationOf($two)->external_main_id);
        $this->assertSame($keep->id, $this->nominationOf($one)->external_main_id);
        $this->assertDatabaseHas('examiner_seat_history', [
            'nomination_id' => $this->nominationOf($two)->id, 'seat' => 'external_main_id', 'examiner_id' => $keep->id, 'event' => 'merged',
        ]);

        $this->assertSame($count, Examiner::count());
        $other->refresh();
        $this->assertFalse($other->is_active);
        $this->assertSame("Duplicate of {$keep->name} (#{$keep->id})", $other->unavailable_reason);

        // Merged without double entries, and the old record keeps its own.
        $this->assertEqualsCanonicalizing(['corrosion', 'pipelines'], $keep->expertiseKeywords()->pluck('keyword')->all());
        $this->assertEqualsCanonicalizing(['Shared Paper', 'Second Paper'], $keep->publications()->pluck('title')->all());
        $this->assertSame('A. Lee', $keep->publications()->where('title', 'Second Paper')->value('coauthors'));
        $this->assertSame(2, $other->expertiseKeywords()->count());
        $this->assertSame(2, $other->publications()->count());

        // Logged.
        $merge = ExaminerMerge::sole();
        $this->assertSame([$keep->id, $other->id, $this->senior->id], [$merge->kept_id, $merge->duplicate_id, $merge->merged_by_id]);
        $this->assertCount(1, $merge->summary['seats']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'examiner', 'subject_id' => $keep->id]);

        // Not flagged again, here or in the pool; cannot be reactivated.
        $this->actingAs($this->senior)->get(route('conflict-detection.index'))
            ->assertDontSee('Possibly the same person')
            ->assertSee('1 examiner is named by more than one department.');
        $this->actingAs($this->senior)->post(route('examiner-admin.toggle-active', $other))->assertSessionHas('error');
        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_only_the_senior_executive_can_merge_and_rejected_lists_stay_as_they_were(): void
    {
        [$keep, $other, , $two] = $this->twoRecords();

        foreach ([$this->director, $this->dean, $this->cast['c']['ae']] as $user) {
            $this->merge($keep, $other, $user)->assertForbidden();
        }
        $this->assertDatabaseCount('examiner_merges', 0);

        $this->actingAs($this->cast['p']['ae'])->post(route('examiner-nomination.decide', $two), ['decision' => 'reject', 'remarks' => 'No.']);

        $this->merge($keep, $other)->assertSessionHas('status');
        $this->assertSame($other->id, $this->nominationOf($two)->external_main_id);
    }

    public function test_merging_a_released_panel_carries_the_tie_up_and_the_gap(): void
    {
        [$keep, $other, , $two] = $this->twoRecords();
        $this->advance($two);
        $until = $other->fresh()->assigned_until;
        $this->assertNotNull($until);

        $viva = Application::create([
            'student_id' => $this->cast['p']['student']->id, 'module_type' => 'viva_voce',
            'status' => Application::STATUS_PENDING, 'submitted_at' => now(),
        ]);
        $progress = VivaExaminerProgress::create([
            'viva_application_id' => $viva->id, 'nomination_id' => $this->nominationOf($two)->id,
            'seat' => 'external_main_id', 'examiner_id' => $other->id, 'letter_sent_at' => now(), 'letter_source' => 'manual',
        ]);

        $keep->update(['last_examination_date' => now()->subYear()]);
        $other->update(['last_examination_date' => now()->subDays(10)]);
        $other->refresh();
        $gapFrom = $other->last_examination_date;

        $this->merge($keep, $other)->assertSessionHas('status');
        $keep->refresh();

        // One person: still tied up, still on the gap the other record was serving.
        $this->assertTrue($until->isSameDay($keep->assigned_until));
        $this->assertTrue($gapFrom->isSameDay($keep->last_examination_date));
        $this->assertNull($other->fresh()->assigned_until);
        $this->assertSame($keep->id, $this->nominationOf($two)->external_main_id);

        // Viva progress follows, so the letter and report dates are not lost.
        $this->assertSame($keep->id, $progress->fresh()->examiner_id);
        $this->assertNotNull($progress->fresh()->letter_sent_at);
    }

    public function test_one_person_never_holds_two_seats_after_a_merge(): void
    {
        $main = $this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org');
        $backup = $this->external('Hassan Omar, PhD', 'h.omar@example.org');
        $panel = $this->nominationOf($this->nominate('c', $main, ['external_backup_id' => $backup->id]));

        // Keeping the backup's record: the person takes the main seat.
        $this->merge($backup, $main)->assertSessionHas('status');
        $panel->refresh();

        $this->assertSame($backup->id, $panel->external_main_id);
        $this->assertNull($panel->external_backup_id);
        $this->assertDatabaseHas('examiner_seat_history', ['nomination_id' => $panel->id, 'seat' => 'external_backup_id', 'examiner_id' => null, 'event' => 'merged']);

        // And the other way round: the backup seat empties.
        $main2 = $this->external('Dr Siti Nurhaliza Rahman', 'siti@example.org');
        $backup2 = $this->external('Siti Nurhaliza Rahman, PhD', 'snr@example.org');
        $panel2 = $this->nominationOf($this->nominate('p', $main2, ['external_backup_id' => $backup2->id]));

        $this->merge($main2, $backup2)->assertSessionHas('status');
        $panel2->refresh();

        $this->assertSame($main2->id, $panel2->external_main_id);
        $this->assertNull($panel2->external_backup_id);
    }

    public function test_an_open_request_follows_the_merge(): void
    {
        Notification::fake();
        [$keep, $other] = [$this->external('Prof Dr Hassan bin Omar', 'hassan.omar@example.org'), $this->external('Hassan Omar, PhD', 'h.omar@example.org')];
        $this->nominate('c', $keep);
        $application = $this->advance($this->nominate('p', $keep), 'cgs_compile');
        $this->ask($this->senior, $application);

        $this->merge($other, $keep)->assertSessionHas('status');

        $this->assertSame($other->id, ExaminerSubstitutionRequest::sole()->examiner_id);
        $this->assertSame('open', ExaminerSubstitutionRequest::sole()->status);
    }

    public function test_the_email_moves_only_when_the_kept_record_has_no_working_one(): void
    {
        $keep = $this->external('Prof Dr Hassan bin Omar', null);
        $other = $this->external('Hassan Omar, PhD', 'h.omar@example.org');
        $this->nominate('p', $other);

        $this->merge($keep, $other)->assertSessionHas('status');

        $this->assertSame('h.omar@example.org', $keep->fresh()->email);
        $this->assertNull($other->fresh()->email);
        $this->assertDatabaseHas('examiner_contact_history', ['examiner_id' => $keep->id, 'new_email' => 'h.omar@example.org', 'source' => 'merge']);
        $this->assertDatabaseHas('examiner_contact_history', ['examiner_id' => $other->id, 'old_email' => 'h.omar@example.org', 'new_email' => null, 'source' => 'merge']);

        // A working address stays put.
        $keep2 = $this->external('Dr Siti Nurhaliza Rahman', 'siti@example.org');
        $other2 = $this->external('Siti Nurhaliza Rahman, PhD', 'snr@example.org');
        $this->merge($keep2, $other2)->assertSessionHas('status');
        $this->assertSame('siti@example.org', $keep2->fresh()->email);
        $this->assertSame('snr@example.org', $other2->fresh()->email);
    }

    public function test_a_merge_never_bypasses_a_rule(): void
    {
        [$keep, $other] = $this->twoRecords();

        // An Unavailable record cannot receive seats.
        $keep->update(['is_active' => false, 'unavailable_reason' => 'Retired']);
        $this->merge($keep, $other)->assertSessionHasErrors('keep_id');
        $this->assertDatabaseCount('examiner_merges', 0);
        $keep->update(['is_active' => true, 'unavailable_reason' => null]);

        // The internal seat needs the candidate's own department.
        $computing = $this->cast['c']['internal'];
        $this->nominate('c');
        $elsewhere = Examiner::create(['name' => 'Dr Internal Computing', 'email' => 'other.dept@example.com', 'department' => 'Petroleum Engineering', 'type' => Examiner::TYPE_INTERNAL, 'is_active' => true]);
        $this->merge($elsewhere, $computing)->assertSessionHasErrors('keep_id');
        $this->assertTrue($computing->fresh()->is_active);

        // Types do not mix, and a record merges once.
        $this->merge($keep, $computing)->assertSessionHasErrors('keep_id');
        $this->merge($keep, $other)->assertSessionHas('status');
        $third = $this->external('Hassan bin Omar', 'third@example.org');
        $this->merge($third, $other)->assertSessionHasErrors('keep_id');

        // Only one of the pair posted can be kept.
        $this->actingAs($this->senior)->post(route('conflict-detection.merge'), ['record_a_id' => $keep->id, 'record_b_id' => $third->id, 'keep_id' => $computing->id])
            ->assertSessionHasErrors('keep_id');
    }

    public function test_the_seat_history_labels_the_new_events(): void
    {
        foreach (['sub_requested' => 'Substitution requested', 'substituted' => 'Substituted by the department', 'merged' => 'Record merged'] as $event => $label) {
            $this->assertLessThanOrEqual(20, strlen($event));
            $this->assertSame($label, (new ExaminerSeatHistory(['event' => $event]))->eventLabel());
        }
    }
}
