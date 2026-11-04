<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use App\Modules\Hani\Support\Pdf\PdfReader;
use App\Modules\Hani\Support\ReVivaCorrections;
use App\Modules\Hani\Workflows\ReVivaWorkflow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item h: levels 1-3, the student's final thesis, "Corrections
 * accepted" and the pre-filled Confirmation of Correction (staff-only).
 */
class ReVivaCorrectionsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function passedRound(int $level = 2): array
    {
        $viva = $this->roundAwaitingOutcome([$level], [$level]);
        Carbon::setTestNow('2027-11-20 10:00');
        $this->recordOutcome($viva, $level)->assertSessionHasNoErrors();
        $viva['round']->refresh();

        return $viva;
    }

    protected function upload(array $viva)
    {
        return $this->actingAs($viva['student'])->post(route('reviva.final-thesis.store', $viva['round']), [
            'final_thesis' => UploadedFile::fake()->create('final-thesis.pdf', 300, 'application/pdf'),
        ]);
    }

    public function test_the_student_uploads_the_final_thesis_and_the_ae_accepts_generating_the_confirmation(): void
    {
        $viva = $this->passedRound();
        $internal = Examiner::find($viva['panel']->internal_main_id);
        $external = Examiner::find($viva['panel']->external_main_id);

        $this->assertContains('reviva.mine', array_column((new ReVivaWorkflow())->links($viva['student']), 'route'));
        $this->actingAs($viva['student'])->get(route('reviva.mine'))
            ->assertOk()
            ->assertSee('Level 2: Minor corrections')
            ->assertSee('20 Feb 2028')
            ->assertSee('Upload Final Thesis')
            ->assertDontSee($internal->name)
            ->assertDontSee($external->name)
            ->assertDontSee('correction-letter')
            ->assertDontSee('UTP/CGS/024');

        // Not before the thesis arrives.
        $this->actingAs($viva['ae'])->post(route('reviva.corrections.accept', $viva['round']))->assertSessionHasErrors('corrections');

        $this->upload($viva)->assertSessionHasNoErrors();
        $this->assertNotNull($viva['round']->fresh()->final_thesis_submitted_at);
        $this->assertSame(1, $viva['round']->application->documents()->where('doc_type', ReVivaCorrections::DOC_FINAL)->count());
        Notification::assertSentTo($viva['ae'], ReVivaUpdate::class, fn ($n) => $n->kind === 'final_thesis_submitted');

        $this->actingAs($viva['ae'])->get(route('reviva.outcomes.index'))->assertSee('Corrections accepted');
        $this->actingAs($viva['ae'])->post(route('reviva.corrections.accept', $viva['round']))->assertSessionHasNoErrors();

        $round = $viva['round']->fresh();
        $this->assertNotNull($round->corrections_accepted_at);

        $confirmation = ReVivaStaffDocument::where('kind', ReVivaStaffDocument::CONFIRMATION)->sole();
        $this->assertStringStartsWith('hani/reviva-confirmations/', $confirmation->path);
        $text = preg_replace('/\s+/', ' ', implode("\n", (array) app(PdfReader::class)->text(Storage::disk('local')->get($confirmation->path))));
        foreach ([$viva['student']->name, $viva['supervisor']->name, 'MSc Full-Time', 'Thesis of 22001001', $internal->name, $external->name, 'Prof Chair 22001001', 'Example University'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // Staff-only: the student cannot open it, the Non-Executive can.
        $this->actingAs($viva['student'])->get(route('reviva.staff-documents.download', $confirmation))->assertForbidden();
        $this->actingAs($this->cgs())->get(route('reviva.staff-documents.download', $confirmation))->assertOk();

        Notification::assertSentTo($viva['student'], ReVivaUpdate::class, fn ($n) => $n->kind === 'corrections_accepted'
            && ! str_contains($n->text(), $internal->name) && ! str_contains($n->text(), $external->name));

        $this->actingAs($viva['student'])->get(route('reviva.mine'))->assertSee('Your re-viva is complete');
        $this->actingAs($viva['ae'])->post(route('reviva.corrections.accept', $viva['round']))->assertSessionHasErrors('corrections');
    }

    public function test_only_the_students_own_level_one_to_three_round_takes_a_final_thesis(): void
    {
        $viva = $this->passedRound();
        $other = $this->student('22009999', 'someone@test.my');

        $this->actingAs($other)->post(route('reviva.final-thesis.store', $viva['round']), [
            'final_thesis' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        $this->actingAs($viva['student'])->post(route('reviva.final-thesis.store', $viva['round']), [
            'final_thesis' => UploadedFile::fake()->create('x.docx', 10),
        ])->assertSessionHasErrors('final_thesis');

        $failed = $this->roundAwaitingOutcome([4, false], [4, false], '22001005');
        $this->recordOutcome($failed, 5);
        $this->upload($failed)->assertSessionHasErrors('final_thesis');
    }

    public function test_acceptance_is_scoped_to_the_department(): void
    {
        $viva = $this->passedRound();
        $this->upload($viva);

        $this->actingAs($this->academicExecFor('Chemical Engineering'))
            ->post(route('reviva.corrections.accept', $viva['round']))->assertForbidden();
        $this->assertNull($viva['round']->fresh()->corrections_accepted_at);
    }
}
