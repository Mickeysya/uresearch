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

    protected function deanUser(): User
    {
        return User::firstOrCreate(['email' => 'dean@test.my'], ['name' => 'Prof. Ts. Dr. M. Fadzil Hassan', 'password' => 'password', 'role' => Role::DEAN_PGR]);
    }

    /** The Dean's re-viva signature on file (Approve needs it). */
    protected function deanSigns(): void
    {
        \Illuminate\Support\Facades\Storage::disk('local')->put('hani/reviva-signatures/dean.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        \App\Modules\Hani\Models\ReVivaSignature::updateOrCreate(['user_id' => $this->deanUser()->id], ['path' => 'hani/reviva-signatures/dean.png']);
    }

    protected function studentFiles(): array
    {
        return [
            'confirmation' => UploadedFile::fake()->create('confirmation.pdf', 50, 'application/pdf'),
            'summary' => UploadedFile::fake()->create('summary.pdf', 50, 'application/pdf'),
            'thesis' => UploadedFile::fake()->create('thesis.pdf', 100, 'application/pdf'),
        ];
    }

    protected function latestRound(User $student): ReVivaDetail
    {
        return \App\Modules\Hani\Support\ReVivaLogging::latestCycle($student->id);
    }

    protected function act(ReVivaDetail $round, string $action, User $by, array $data = [])
    {
        return $this->actingAs($by)->post(route('reviva.act', [$round->application_id, $action]), $data);
    }

    /** Round 1 submitted by the student on 1 Oct 2027. */
    protected function roundSubmitted(string $matric = '22001001'): array
    {
        \Carbon\Carbon::setTestNow('2027-03-15 09:00');
        $viva = $this->categoryFourViva($matric);
        \Carbon\Carbon::setTestNow('2027-10-01 09:00');
        $this->actingAs($viva['student'])->post(route('reviva.submit'), $this->studentFiles())->assertSessionHasNoErrors();
        $viva['round'] = $this->latestRound($viva['student']);

        return $viva;
    }

    protected function roundReadyToSend(string $matric = '22001001'): array
    {
        $viva = $this->roundSubmitted($matric);
        $this->act($viva['round'], 'accept', $this->senior())->assertSessionHasNoErrors();
        $this->act($viva['round'], 'send-to-dean', $this->senior())->assertSessionHasNoErrors();
        $this->deanSigns();
        $this->act($viva['round'], 'dean-approve', $this->deanUser())->assertSessionHasNoErrors();

        return $viva;
    }

    /** Sent on Fri 1 Oct 2027. */
    protected function roundUnderPanelReview(string $matric = '22001001'): array
    {
        $viva = $this->roundReadyToSend($matric);
        $this->act($viva['round'], 'send-to-panel', $this->senior())->assertSessionHasNoErrors();
        $viva['round']->refresh();

        return $viva;
    }

    protected function recordReturn(ReVivaDetail $round, string $seat, int $category, ?int $vivaAgain = null)
    {
        return $this->actingAs($this->senior())->post(route('reviva.evaluations.record', [$round->application_id, $seat]), array_filter([
            'category' => $category, 'viva_again' => $vivaAgain,
            'report' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf'),
            'signed_letter' => UploadedFile::fake()->create('s.pdf', 20, 'application/pdf'),
        ], fn ($v) => $v !== null));
    }

    protected function roundAtReview(int $internal, int $external, ?int $vivaInternal = null, ?int $vivaExternal = null): array
    {
        $viva = $this->roundUnderPanelReview();
        $this->recordReturn($viva['round'], 'internal_main_id', $internal, $vivaInternal)->assertSessionHasNoErrors();
        $this->recordReturn($viva['round'], 'external_main_id', $external, $vivaExternal)->assertSessionHasNoErrors();
        $this->act($viva['round'], 'start-review', $this->senior())->assertSessionHasNoErrors();

        return $viva;
    }
}
