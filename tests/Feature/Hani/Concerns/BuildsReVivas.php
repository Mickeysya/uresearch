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

    protected function latestRound(User $student): ?ReVivaDetail
    {
        return ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id)->where('module_type', 're_viva'))
            ->latest('cycle_number')->first();
    }
}
