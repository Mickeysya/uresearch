<?php

namespace Tests\Feature\Hani\Concerns;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\VivaResult;
use Illuminate\Http\UploadedFile;

/**
 * Re-viva fixtures on top of BuildsVivas: a category-4 viva, and a round
 * logged through the Senior Executive's form. Fictional people only.
 */
trait BuildsReVivas
{
    use BuildsVivas;

    protected ?User $seniorExecUser = null;

    protected function senior(): User
    {
        return $this->seniorExecUser ??= User::firstOrCreate(
            ['email' => 'waheeda@test.my'],
            ['name' => 'Puan Waheeda', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS],
        );
    }

    /** A viva held on $vivaAt with result category 4, recorded through the AE's service. */
    protected function categoryFourViva(string $matric = '22001001', string $vivaAt = '2027-03-01 10:00', string $programme = 'MSc Full-Time'): array
    {
        $viva = $this->viva($matric, $programme);
        $viva['detail']->update(['viva_at' => $vivaAt, 'venue' => 'Room 1', 'chair_name' => 'Prof Chair '.$matric, 'chair_email' => "chair{$matric}@example.com"]);

        app(VivaResult::class)->record(
            $viva['notice'], 4,
            UploadedFile::fake()->create('viva-report.pdf', 100, 'application/pdf'),
            $viva['ae'],
        );
        $viva['detail']->refresh();

        return $viva;
    }

    /** The Senior Executive logs a round: both uploads, plus any extra fields. */
    protected function logReViva(User $student, array $extra = [])
    {
        return $this->actingAs($this->senior())->post(route('reviva.store'), $extra + [
            'student_id' => $student->id,
            'thesis' => UploadedFile::fake()->create('corrected-thesis.pdf', 100, 'application/pdf'),
            'summary_of_corrections' => UploadedFile::fake()->create('summary-of-corrections.pdf', 50, 'application/pdf'),
        ]);
    }

    /** A category-4 viva (1 Mar 2027) and round 1 logged inside its window on 1 Oct 2027: at stage 1. */
    protected function roundAtStageOne(string $matric = '22001001'): array
    {
        \Carbon\Carbon::setTestNow('2027-03-15 09:00');
        $viva = $this->categoryFourViva($matric);
        \Carbon\Carbon::setTestNow('2027-10-01 09:00');
        $this->logReViva($viva['student'])->assertSessionHasNoErrors();
        $viva['round'] = $this->latestRound($viva['student']);

        return $viva;
    }

    protected function sendToPanel(array $viva)
    {
        return $this->actingAs($viva['ae'])->post(route('reviva.send-to-panel', $viva['round']->application_id));
    }

    /** Letters sent on Fri 1 Oct 2027: Under Panel Review. */
    protected function roundUnderPanelReview(string $matric = '22001001'): array
    {
        $viva = $this->roundAtStageOne($matric);
        $this->sendToPanel($viva)->assertSessionHasNoErrors();
        $viva['round']->refresh();

        return $viva;
    }

    /** Stage 2 completed: Report Received, where the re-evaluations are recorded. */
    protected function roundAtReportReceived(string $matric = '22001001'): array
    {
        $viva = $this->roundUnderPanelReview($matric);
        $this->actingAs($viva['ae'])->post(route('reviva.decide', $viva['round']->application_id), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        return $viva;
    }

    protected function recordEvaluation(array $viva, string $seat, int $category, ?bool $vivaAgain = null, array $extra = [])
    {
        return $this->actingAs($viva['ae'])->post(route('reviva.evaluations.record', [$viva['round']->application_id, $seat]), $extra + array_filter([
            'category' => $category,
            'viva_again' => $vivaAgain === null ? null : (int) $vivaAgain,
            'report' => UploadedFile::fake()->create("utp-cgs-024-{$seat}.pdf", 80, 'application/pdf'),
        ], fn ($v) => $v !== null));
    }

    protected function advance(array $viva)
    {
        return $this->actingAs($viva['ae'])->post(route('reviva.decide', $viva['round']->application_id), ['decision' => 'approve']);
    }

    protected function latestRound(User $student): ?ReVivaDetail
    {
        return ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id)->where('module_type', 're_viva'))
            ->latest('cycle_number')->first();
    }
}
