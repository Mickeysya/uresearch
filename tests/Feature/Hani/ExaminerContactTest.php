<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerContactHistory;
use App\Modules\Hani\Models\ExaminerEmailProposal;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Http\UploadedFile;
use App\Modules\Hani\Notifications\ExaminerEmailReported;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Notifications\VivaScheduled;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use App\Modules\Hani\Support\ReleasedExaminerProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Keeping examiner emails current: the Non-Exec email-only edit, the
 * contact history, the "not working" flag, freshness, and emails proposed
 * at nomination.
 */
class ExaminerContactTest extends TestCase
{
    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        config(['hani.scheduling.public_holidays' => []]);
        Carbon::setTestNow('2027-01-04 08:00');   // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function schedule(array $viva, string $date = '2027-01-14')
    {
        return $this->actingAs($viva['ae'])->post(route('viva.schedule.update', $viva['notice']), [
            'viva_date' => $date, 'viva_time' => '10:00', 'venue' => 'Room 1', 'chair_name' => 'Prof Chair',
            'chair_email' => 'chair@example.com', 'override_warnings' => 1,
        ]);
    }

    /** Was the viva invitation emailed to this address? */
    protected function invited(string $email, string $class = VivaScheduled::class): bool
    {
        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byNotifiable) {
            foreach ($byNotifiable[$class] ?? [] as $sent) {
                if (array_key_exists($email, (array) $sent['notifiable']->routes['mail'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function external(array $attributes = []): Examiner
    {
        return Examiner::create($attributes + [
            'name' => 'Prof Dr Zainal Ariffin',
            'email' => 'zainal@old.example.com',
            'department' => 'Mechanical Engineering',
            'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Example University of Technology',
            'is_active' => true,
        ]);
    }

    public function test_non_exec_can_change_the_email_and_nothing_else(): void
    {
        $examiner = $this->external();
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))->assertOk()->assertSee('Update email')->assertDontSee('Edit examiner');
        $this->actingAs($nonExec)->get(route('examiner-admin.email.edit', $examiner))->assertOk();

        // Everything else posted alongside is ignored.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'Zainal@NEW.example.com',
            'reason' => 'New address from the university staff page',
            'name' => 'Someone Else',
            'department' => 'Petroleum Engineering',
            'is_active' => 0,
        ])->assertRedirect(route('examiner-admin.show', $examiner))->assertSessionHasNoErrors();

        $examiner->refresh();
        $this->assertSame('zainal@new.example.com', $examiner->email);
        $this->assertSame('Prof Dr Zainal Ariffin', $examiner->name);
        $this->assertSame('Mechanical Engineering', $examiner->department);
        $this->assertTrue($examiner->is_active);

        // The full form stays the Senior Executive's.
        $this->actingAs($nonExec)->put(route('examiner-admin.update', $examiner), ['name' => 'X'])->assertForbidden();

        // A reason is required, and an address another examiner has is refused.
        $this->external(['name' => 'Dr Other', 'email' => 'other@example.com']);
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'other@example.com', 'reason' => 'x'])
            ->assertSessionHasErrors('email');

        // Others cannot use it at all.
        $this->actingAs($this->supervisor())->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com', 'reason' => 'x'])->assertForbidden();
    }

    public function test_every_email_change_is_logged_and_shown_on_the_examiner_page(): void
    {
        $senior = $this->seniorExec();
        $nonExec = $this->cgs();

        // Added with an email on the full form.
        $this->actingAs($senior)->post(route('examiner-admin.store'), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@first.example.com', 'department' => 'Computing', 'type' => 'internal',
        ])->assertSessionHasNoErrors();
        $examiner = Examiner::where('name', 'Dr Farah Hanim')->firstOrFail();

        // Changed by Non-Exec, then by the Senior Exec on the full form.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'farah@second.example.com', 'reason' => 'Bounced; new address from her department',
        ]);
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
            'email_change_reason' => 'Moved office',
        ])->assertSessionHasNoErrors();

        // Saving the form without touching the email logs nothing.
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim Binti Yusof', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
        ]);

        $lines = ExaminerContactHistory::where('examiner_id', $examiner->id)->orderBy('id')->get();
        $this->assertSame([
            [null, 'farah@first.example.com', 'manual', 'Senior Executive CGS'],
            ['farah@first.example.com', 'farah@second.example.com', 'email_form', 'Non-Executive CGS'],
            ['farah@second.example.com', 'farah@third.example.com', 'manual', 'Senior Executive CGS'],
        ], $lines->map(fn ($l) => [$l->old_email, $l->new_email, $l->source, \App\Modules\Core\Support\Role::label($l->changedBy->role)])->all());
        $this->assertSame('Bounced; new address from her department', $lines[1]->reason);

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))
            ->assertOk()
            ->assertSee('Contact history')
            ->assertSee('farah@first.example.com')
            ->assertSee('Bounced; new address from her department')
            ->assertSee('M Syahmi Ifwat M Jafri');
    }

    public function test_an_academic_executive_reports_an_email_not_working_and_cgs_is_told(): void
    {
        $viva = $this->viva();
        $external = $viva['panel']->externalMain;
        $nonExec = $this->cgs();
        $senior = $this->seniorExec();

        // From the viva examiner-progress screen.
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))->assertOk()->assertSee('Report email not working');
        $this->actingAs($viva['ae'])->from(route('viva.examiners.show', $viva['notice']))
            ->post(route('examiner-admin.report-email', $external), [])
            ->assertSessionHasErrors('reason');
        $this->actingAs($viva['ae'])->from(route('viva.examiners.show', $viva['notice']))
            ->post(route('examiner-admin.report-email', $external), ['reason' => 'Invitation bounced: mailbox does not exist'])
            ->assertRedirect(route('viva.examiners.show', $viva['notice']));

        $external->refresh();
        $this->assertSame(Examiner::EMAIL_INVALID, $external->emailStatus());
        $this->assertNotNull($external->email_flagged_at);
        $this->assertSame('external_main.22001001@example.com', $external->email, 'The address is kept, only flagged.');
        $line = ExaminerContactHistory::where('examiner_id', $external->id)->sole();
        $this->assertSame(['reported', 'Invitation bounced: mailbox does not exist', $viva['ae']->id], [$line->source, $line->reason, $line->changed_by_id]);

        Notification::assertSentTo([$nonExec, $senior], ExaminerEmailReported::class);
        Notification::assertNotSentTo($viva['ae'], ExaminerEmailReported::class);

        // Badges: the pool, the examiner page, the viva screen, the panel.
        $this->actingAs($nonExec)->get(route('examiner-admin.index'))->assertSee('Email needs updating');
        $this->actingAs($viva['ae'])->get(route('examiner-admin.show', $external))->assertOk()->assertSee('Email needs updating')->assertSee('reported not working');
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))->assertSee('Email needs updating');
        $this->actingAs($senior)->get(route('examiner-nomination.report'))->assertOk()->assertSee('Email needs updating');

        // An AE from another department may neither open nor report this examiner.
        $other = $this->academicExecFor('Petroleum Engineering');
        $this->actingAs($other)->get(route('examiner-admin.show', $external))->assertForbidden();
        $this->actingAs($other)->post(route('examiner-admin.report-email', $viva['panel']->internalMain), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->supervisor())->post(route('examiner-admin.report-email', $external), ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_badge_shows_on_the_nomination_form_and_in_the_suggestions(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);
        $examiner = $this->external(['department' => 'Computing']);
        $examiner->expertiseKeywords()->create(['keyword' => 'anomaly detection']);
        $examiner->update(['email_status' => Examiner::EMAIL_INVALID, 'email_flagged_at' => now()]);

        $this->actingAs($supervisor)->get(route('examiner-nomination.create'))->assertOk()->assertSee('[Email needs updating]');

        $suggested = collect(app(ExaminerSuggestions::class)->forStudent($student, 'Anomaly detection for sensor data')['external'])->firstWhere('id', $examiner->id);
        $this->assertContains('Email needs updating: reported not working.', array_column($suggested['flags'], 'message'));
    }

    public function test_an_invalid_email_blocks_invitations_reminders_and_letters_until_it_is_updated(): void
    {
        $viva = $this->viva();
        $external = $viva['panel']->externalMain;
        $address = $external->email;
        $nonExec = $this->cgs();
        app(\App\Modules\Hani\Support\ExaminerContact::class)->report($external, $nonExec, 'Bounced');

        // The viva invitation goes to everyone else, and the AE is told why not to the examiner.
        $this->schedule($viva)->assertSessionHas('warning', fn ($w) => str_contains($w, "{$external->name}'s email was reported not working"));
        $this->assertFalse($this->invited($address));
        $this->assertTrue($this->invited('internal_main.22001001@example.com'));

        // The panel reminders skip them too.
        Carbon::setTestNow('2027-01-07 07:45');
        $this->artisan('viva:reminders')->expectsOutputToContain('examiner skipped')->assertSuccessful();
        $this->assertFalse($this->invited($address, VivaReminder::class));

        // The appointment letter module gets no address, and the reason.
        $panel = app(ReleasedExaminerProvider::class)->panelForStudent($viva['student']->id);
        $this->assertNull($panel['examiners']['external_main']['email']);
        $this->assertStringContainsString('reported not working', $panel['examiners']['external_main']['email_problem']);
        $this->assertSame('internal_main.22001001@example.com', $panel['examiners']['internal_main']['email']);

        // Updating the email clears the flag, and the next invitation reaches them.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $external), [
            'email' => 'new.address@example.com', 'reason' => 'From the university staff directory',
        ])->assertSessionHasNoErrors();
        $external->refresh();
        $this->assertSame(Examiner::EMAIL_OK, $external->emailStatus());
        $this->assertNull($external->email_flagged_at);

        $this->schedule($viva, '2027-01-21')->assertSessionMissing('warning');
        $this->assertTrue($this->invited('new.address@example.com'));
    }

    public function test_email_freshness_is_set_on_change_and_confirm_and_filtered_after_12_months(): void
    {
        $nonExec = $this->cgs();
        $fresh = $this->external(['name' => 'Dr Fresh', 'email' => 'fresh@example.com', 'email_confirmed_at' => now()->subMonths(2)]);
        $stale = $this->external(['name' => 'Dr Stale', 'email' => 'stale@example.com', 'email_confirmed_at' => now()->subMonths(13)]);
        $never = $this->external(['name' => 'Dr Never Confirmed', 'email' => 'never@example.com']);
        $missing = $this->external(['name' => 'Dr Missing', 'email' => null]);
        $invalid = $this->external(['name' => 'Dr Invalid', 'email' => 'bad@example.com', 'email_status' => Examiner::EMAIL_INVALID, 'email_confirmed_at' => now()->subMonths(20)]);
        $retired = $this->external(['name' => 'Dr Retired', 'email' => null, 'is_active' => false, 'unavailable_reason' => 'Retired']);

        $names = fn ($response) => collect(['Dr Fresh', 'Dr Stale', 'Dr Never Confirmed', 'Dr Missing', 'Dr Invalid', 'Dr Retired'])
            ->filter(fn ($n) => str_contains($response->getContent(), $n))->values()->all();

        // Stale: an OK address older than 12 months, or never confirmed.
        $this->assertSame(['Dr Stale', 'Dr Never Confirmed'], $names($this->actingAs($nonExec)->get(route('examiner-admin.index', ['email' => 'stale']))));
        // Missing or invalid: active examiners only.
        $this->assertSame(['Dr Missing', 'Dr Invalid'], $names($this->actingAs($nonExec)->get(route('examiner-admin.index', ['email' => 'problem']))));

        // The dashboard tiles count the same.
        $this->actingAs($nonExec)->get(route('examiner-dashboard'))->assertOk()
            ->assertSeeInOrder(['Missing or invalid emails', 'data-email-tile="problem">2<', 'Emails not confirmed in 12 months', 'data-email-tile="stale">2<'], false);

        // Confirming restarts the clock and is logged.
        $this->actingAs($nonExec)->post(route('examiner-admin.email.confirm', $stale))->assertSessionHasNoErrors();
        $this->assertTrue($stale->fresh()->email_confirmed_at->isToday());
        $this->assertFalse($stale->fresh()->emailStale());
        $this->assertSame('confirmed', ExaminerContactHistory::where('examiner_id', $stale->id)->sole()->source);

        // Changing the email sets it too.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $never), ['email' => 'never.new@example.com', 'reason' => 'Checked']);
        $this->assertTrue($never->fresh()->email_confirmed_at->isToday());
        $this->assertFalse($never->fresh()->emailStale());

        // Confirming a reported email clears the flag (a report can be wrong).
        $this->actingAs($nonExec)->post(route('examiner-admin.email.confirm', $invalid));
        $this->assertSame(Examiner::EMAIL_OK, $invalid->fresh()->emailStatus());

        // Only CGS confirms.
        $this->actingAs($this->academicExec())->post(route('examiner-admin.email.confirm', $fresh))->assertForbidden();

        // An AE's tiles cover their own department.
        $ae = $this->academicExecFor('Petroleum Engineering');
        $this->actingAs($ae)->get(route('examiner-dashboard'))->assertOk()
            ->assertSee('data-email-tile="problem">0<', false)
            ->assertDontSee(route('examiner-admin.index', ['email' => 'problem']));
    }

    public function test_existing_examiners_are_confirmed_as_of_the_migration(): void
    {
        $migration = require base_path('app/Modules/Hani/Database/Migrations/2026_11_05_000200_add_email_status_to_examiners_table.php');
        $migration->down();

        \Illuminate\Support\Facades\DB::table('examiners')->insert([
            ['name' => 'Dr With Email', 'email' => 'with@example.com', 'department' => 'Computing', 'type' => 'internal', 'is_active' => true],
            ['name' => 'Dr Without', 'email' => null, 'department' => 'Computing', 'type' => 'internal', 'is_active' => true],
        ]);
        $migration->up();

        $this->assertTrue(Examiner::where('name', 'Dr With Email')->value('email_confirmed_at') !== null);
        $this->assertNull(Examiner::where('name', 'Dr Without')->value('email_confirmed_at'));
        $this->assertSame(['ok', 'ok'], Examiner::orderBy('name')->pluck('email_status')->all());
    }

    /** A supervisor and candidate in Computing, and a legal panel whose external main has $externalEmail. */
    protected function nominationCast(?string $externalEmail, string $status = Examiner::EMAIL_OK): array
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);
        $make = fn (string $name, string $type, ?string $email) => Examiner::create([
            'name' => $name, 'email' => $email, 'department' => $type === 'internal' ? 'Computing' : 'External',
            'type' => $type, 'institution' => $type === 'external' ? 'Example University' : null, 'is_active' => true,
        ]);

        $external = $make('Prof Nabila External', 'external', $externalEmail);
        $external->update(['email_status' => $status]);

        return [$supervisor, $student, $external, [
            'student_id' => $student->id,
            'thesis_title' => 'Anomaly detection for offshore pipelines',
            'internal_main_id' => $make('Prof Tan Internal', 'internal', 'tan@example.com')->id,
            'internal_backup_id' => $make('Dr Kumar Internal', 'internal', 'kumar@example.com')->id,
            'external_main_id' => $external->id,
            'external_backup_id' => $make('Dr Chong External', 'external', 'chong@example.com')->id,
        ]];
    }

    public function test_a_supervisor_proposes_an_email_and_cv_and_cgs_confirms_it(): void
    {
        [$supervisor, $student, $external, $panel] = $this->nominationCast('nabila@dead.example.com', Examiner::EMAIL_INVALID);

        // The form asks when the chosen examiner has no working email.
        $this->actingAs($supervisor)->get(route('examiner-nomination.create'))
            ->assertOk()->assertSee('data-email-state="invalid"', false)->assertSee('name="proposed_email[external_main_id]"', false);

        // The dead address is refused as a "new" one.
        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), $panel + ['proposed_email' => ['external_main_id' => 'NABILA@dead.example.com']])
            ->assertSessionHasErrors('proposed_email.external_main_id');

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), $panel + [
            'proposed_email' => ['external_main_id' => 'Nabila@NEW.example.com', 'internal_main_id' => 'ignored@example.com'],
            'proposed_cv' => ['external_main_id' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        // Proposed, not applied. The internal main's email works, so nothing was proposed for them.
        $proposal = ExaminerEmailProposal::sole();
        $this->assertSame([$external->id, 'nabila@new.example.com', $supervisor->id, 'pending'], [$proposal->examiner_id, $proposal->proposed_email, $proposal->proposed_by_id, $proposal->status]);
        $this->assertSame('nabila@dead.example.com', $external->fresh()->email);
        Storage::disk('local')->assertExists($proposal->cv_path);

        // The CV is not an application document, so the candidate never sees it.
        $this->assertSame(0, ApplicationDocument::count());
        $this->actingAs($student)->get(route('examiner-admin.proposal.cv', $proposal))->assertForbidden();
        $this->actingAs($supervisor)->get(route('examiner-admin.proposal.cv', $proposal))->assertForbidden();

        // CGS sees it in the pool, downloads the CV, and confirms with one click.
        $nonExec = $this->cgs();
        $this->actingAs($nonExec)->get(route('examiner-admin.index'))->assertSee('Proposed emails to confirm (1)')->assertSee('nabila@new.example.com');
        $this->actingAs($nonExec)->get(route('examiner-admin.proposal.cv', $proposal))->assertOk();
        $this->actingAs($nonExec)->post(route('examiner-admin.proposal.confirm', $proposal))->assertSessionHasNoErrors();

        $external->refresh();
        $this->assertSame('nabila@new.example.com', $external->email);
        $this->assertSame(Examiner::EMAIL_OK, $external->emailStatus());
        $this->assertTrue($external->email_confirmed_at->isToday());
        $this->assertSame('confirmed', $proposal->fresh()->status);
        $line = ExaminerContactHistory::where('examiner_id', $external->id)->where('source', 'proposal')->sole();
        $this->assertSame(['nabila@dead.example.com', 'nabila@new.example.com', $nonExec->id], [$line->old_email, $line->new_email, $line->changed_by_id]);
        $this->assertStringContainsString($supervisor->name, $line->reason);
        $this->actingAs($nonExec)->get(route('examiner-admin.index'))->assertDontSee('Proposed emails to confirm');
    }

    public function test_a_valid_email_is_never_overwritten_by_a_proposal(): void
    {
        [$supervisor, , $external, $panel] = $this->nominationCast(null);

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), $panel + ['proposed_email' => ['external_main_id' => 'first@example.com']]);
        $proposal = ExaminerEmailProposal::sole();

        // Meanwhile CGS found an address on their own.
        $nonExec = $this->cgs();
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $external), ['email' => 'found@example.com', 'reason' => 'University directory']);

        $this->actingAs($nonExec)->post(route('examiner-admin.proposal.confirm', $proposal))->assertSessionHasErrors('proposal');
        $this->assertSame('found@example.com', $external->fresh()->email);
        $this->assertSame('pending', $proposal->fresh()->status);

        $this->actingAs($nonExec)->post(route('examiner-admin.proposal.dismiss', $proposal))->assertSessionHasNoErrors();
        $this->assertSame('dismissed', $proposal->fresh()->status);

        // And the helper itself refuses to propose for a working email.
        $this->assertNull(app(\App\Modules\Hani\Support\ExaminerContact::class)->propose($external->fresh(), 'x@example.com', $supervisor));

        // Only CGS confirms.
        $this->actingAs($this->academicExec())->post(route('examiner-admin.proposal.dismiss', $proposal))->assertForbidden();
    }
}
