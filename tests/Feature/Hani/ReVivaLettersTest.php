<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaCorrectionLetter;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\Pdf\PdfReader;
use App\Modules\Hani\Support\ReVivaLetters;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item d: "Send to panel" generates one correction letter per
 * current main examiner, stores it staff-only, emails it with a signed link
 * (skipping flagged emails) and completes stage 1 through the engine.
 */
class ReVivaLettersTest extends TestCase
{
    use BuildsReVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow('2027-03-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function send(array $viva)
    {
        return $this->sendToPanel($viva);
    }

    public function test_send_to_panel_writes_a_letter_per_current_main_stores_it_staff_only_and_completes_stage_one(): void
    {
        $viva = $this->roundAtStageOne();
        $panel = $viva['panel'];

        // The internal main declined after the viva: the backup now holds the seat.
        $declined = Examiner::find($panel->internal_main_id);
        $backup = Examiner::find($panel->internal_backup_id);
        app(PanelSeats::class)->decline($panel, 'internal_main_id', 'Retired.', $this->senior());

        $this->send($viva)->assertSessionHasNoErrors()->assertSessionMissing('warning');

        $letters = ReVivaStaffDocument::where('kind', ReVivaStaffDocument::LETTER)->get();
        $this->assertEqualsCanonicalizing([$backup->id, $panel->external_main_id], $letters->pluck('examiner_id')->all());
        $this->assertNotContains($declined->id, $letters->pluck('examiner_id')->all());

        foreach ($letters as $letter) {
            $this->assertStringStartsWith('hani/reviva-letters/', $letter->path);
            Storage::disk('local')->assertExists($letter->path);
            $this->assertNotNull($letter->emailed_at);
        }

        // Not in DocumentStore: the student's application still holds only their two uploads.
        $application = Application::find($viva['round']->application_id);
        $this->assertSame(['Re-corrected Thesis', 'Summary of Corrections'], $application->documents->pluck('doc_type')->sort()->values()->all());

        // Stage 1 completed through the engine, in the same action.
        $this->assertSame('under_panel_review', $application->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertNotNull($viva['round']->fresh()->sent_to_panel_at);

        Notification::assertSentOnDemandTimes(ReVivaCorrectionLetter::class, 2);
        Notification::assertSentOnDemand(ReVivaCorrectionLetter::class, fn ($n, $channels, $notifiable) => array_key_exists($backup->email, $notifiable->routes['mail']));

        // A second send is refused.
        $this->send($viva)->assertSessionHasErrors('send');
        $this->assertSame(2, ReVivaStaffDocument::count());
    }

    public function test_the_letter_follows_the_real_layout(): void
    {
        $viva = $this->roundAtStageOne();
        $this->send($viva);

        $letter = ReVivaStaffDocument::where('seat', 'external_main_id')->sole();
        $text = implode("\n", (array) app(PdfReader::class)->text(Storage::disk('local')->get($letter->path)));
        $text = preg_replace('/\s+/', ' ', $text);

        $this->assertStringContainsString('UTP/PGS/AD/22001001', $text);
        $this->assertStringContainsString("CORRECTION OF MASTER'S THESIS", $text);
        $this->assertStringContainsString('Example University', $text);
        $this->assertStringContainsString('Thesis of 22001001', $text);
        $this->assertStringContainsString('twenty (20) working days', $text);
        $this->assertStringContainsString('Dean, Postgraduate & Research', $text);
        $this->assertStringContainsString('Encik Syahmi', $text);
        // 1 Oct 2027 + 20 working days, skipping Deepavali (28 Oct, in the holiday list) = 1 Nov 2027.
        $this->assertStringContainsString('that is by 1 November 2027', $text);
    }

    public function test_a_flagged_email_is_skipped_with_a_clear_message(): void
    {
        $viva = $this->roundAtStageOne();
        $external = Examiner::find($viva['panel']->external_main_id);
        $external->update(['email_status' => Examiner::EMAIL_INVALID, 'email_flagged_at' => now()]);

        $this->actingAs($viva['ae'])->get(route('reviva.queue'))
            ->assertSee('Email needs updating')
            ->assertSee('Send to panel');

        $this->send($viva)->assertSessionHas('warning', fn ($w) => str_contains($w, "{$external->name}'s email was reported not working"));

        $skipped = ReVivaStaffDocument::where('examiner_id', $external->id)->sole();
        $this->assertNull($skipped->emailed_at);
        $this->assertNotNull($skipped->email_skipped_reason);
        Notification::assertSentOnDemandTimes(ReVivaCorrectionLetter::class, 1);
        $this->assertSame('under_panel_review', Application::find($viva['round']->application_id)->current_stage);
    }

    public function test_stage_one_cannot_be_advanced_without_the_letters_and_only_by_the_departments_ae(): void
    {
        $viva = $this->roundAtStageOne();

        $this->actingAs($viva['ae'])->post(route('reviva.decide', $viva['round']->application_id), ['decision' => 'approve'])
            ->assertSessionHas('error');
        $this->assertSame('report_sent', Application::find($viva['round']->application_id)->current_stage);

        $other = $this->academicExecFor('Chemical Engineering');
        $this->actingAs($other)->post(route('reviva.send-to-panel', $viva['round']->application_id))->assertForbidden();
        $this->actingAs($this->cgs())->post(route('reviva.send-to-panel', $viva['round']->application_id))->assertForbidden();
        $this->assertSame(0, ReVivaStaffDocument::count());
    }

    public function test_the_signed_link_works_logged_out_expires_and_rejects_tampering(): void
    {
        $viva = $this->roundAtStageOne();
        $this->send($viva);
        $round = $viva['round']->fresh();
        $examiner = Examiner::find($viva['panel']->external_main_id);

        $link = ReVivaLetters::panelLink($round, $examiner);

        $page = $this->get($link)->assertOk()
            ->assertSee($viva['student']->name)
            ->assertSee('Summary of Corrections')
            ->assertHeader('Content-Security-Policy');
        preg_match_all('/href="([^"]*\/documents\/\d+[^"]*)"/', $page->getContent(), $m);
        $this->assertCount(2, $m[1]);
        $this->get(html_entity_decode($m[1][0]))->assertOk();

        // Tampered: another examiner's id in the same signature.
        $other = Examiner::find($viva['panel']->internal_main_id);
        $this->get(str_replace("/{$examiner->id}?", "/{$other->id}?", $link))->assertForbidden();

        // A document that is not one of the two: refused even with a valid signature.
        $vivaReport = $viva['notice']->documents()->first();
        $foreign = \Illuminate\Support\Facades\URL::temporarySignedRoute('reviva.panel.download', now()->addDay(), [
            'detail' => $round->id, 'examiner' => $examiner->id, 'document' => $vivaReport->id,
        ]);
        $this->get($foreign)->assertNotFound();

        // A backup who was never sent a letter: refused even when correctly signed.
        $backup = Examiner::find($viva['panel']->external_backup_id);
        $this->get(ReVivaLetters::panelLink($round, $backup))->assertForbidden();

        // Expired.
        Carbon::setTestNow(now()->addDays((int) config('hani.reviva.link_days') + 2));
        $this->get($link)->assertForbidden();

        $routes = Route::getRoutes()->getByName('reviva.panel.documents')->gatherMiddleware();
        $this->assertContains('signed', $routes);
        $this->assertContains('throttle:30,1', $routes);
    }

    public function test_letters_are_staff_only_and_the_non_executive_may_download_them(): void
    {
        $viva = $this->roundAtStageOne();
        $this->send($viva);
        $letter = ReVivaStaffDocument::first();
        $nonExec = $this->cgs();

        $this->actingAs($viva['ae'])->get(route('reviva.staff-documents.download', $letter))->assertOk();
        $this->actingAs($this->senior())->get(route('reviva.staff-documents.download', $letter))->assertOk();
        $this->actingAs($nonExec)->get(route('reviva.staff-documents.download', $letter))->assertOk();
        $this->actingAs($nonExec)->get(route('reviva.cycles.index'))->assertSee(route('reviva.staff-documents.download', $letter), false);

        $this->actingAs($this->academicExecFor('Chemical Engineering'))->get(route('reviva.staff-documents.download', $letter))->assertForbidden();
        $this->actingAs($viva['student'])->get(route('reviva.staff-documents.download', $letter))->assertForbidden();

        // The student's tracking page names no examiner.
        $this->actingAs($viva['student'])->get(route('applications.show', $viva['round']->application_id))
            ->assertOk()
            ->assertDontSee(Examiner::find($viva['panel']->external_main_id)->name)
            ->assertDontSee('correction-letter');

        $this->assertSame(3, \Spatie\Activitylog\Models\Activity::where('log_name', 'document')->where('subject_type', ReVivaStaffDocument::class)->count());
    }
}
