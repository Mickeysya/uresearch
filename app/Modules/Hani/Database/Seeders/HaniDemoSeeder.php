<?php

namespace App\Modules\Hani\Database\Seeders;

use App\Modules\Hani\Support\Import\PoolImporter;
use App\Modules\Hani\Support\Import\TemplateLayoutReader;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fictional demo data for Hani's modules, ON DEMAND ONLY:
 *
 *     php artisan db:seed --class="App\Modules\Hani\Database\Seeders\HaniDemoSeeder"
 *
 * Not called from DatabaseSeeder, so a teammate's fresh database never gets
 * it, and it never creates, changes or deletes the shared test accounts in
 * docs/test-accounts.md.
 *
 * Source: Database/demo/hani_demo_dataset.json (every person, email and thesis
 * in it is invented; emails are example.com/.org). Read, never written.
 *
 * Goes through the pool import itself (PoolImporter::prepare() and apply()),
 * so the demo pool obeys exactly the rules a real upload does -- department
 * aliases, internal rows stripped of the external record, duplicate flags --
 * and running it again changes nothing.
 *
 * STRUCTURE FOR LATER PROMPTS: run() walks steps(). Part 1 is the examiner
 * pool. Prompts 2-4 add their own step methods (supervisors and students,
 * viva notices, nominations, re-viva cases) from the same dataset's
 * `supervisors`, `students` and `scenario_notes`, each idempotent the same
 * way.
 */
class HaniDemoSeeder extends Seeder
{
    public const DATASET = __DIR__.'/../demo/hani_demo_dataset.json';

    /** JSON field => template field, for TemplateLayoutReader::record(). */
    protected const EXAMINER_FIELDS = [
        'type' => 'type',
        'name' => 'name',
        'email' => 'email',
        'department' => 'department',
        'faculty' => 'faculty',
        'institution' => 'institution',
        'sector' => 'sector',
        'utp_cluster' => 'utp_cluster',
        'area_of_expertise' => 'expertise',
        'keywords' => 'keywords',
        'years_experience' => 'years_experience',
        'msc_supervised' => 'msc_graduated',
        'phd_supervised' => 'phd_graduated',
        'first_examination_date' => 'first_examination_date',
        'last_examination_date' => 'last_examination_date',
        'status' => 'status',
        'unavailable_reason' => 'unavailable_reason',
        'faculty_approval_ref' => 'faculty_approval',
        'utp_appointment' => 'current_utp_appointment',
        'former_utp_until' => 'former_utp_until',
    ];

    protected ?array $dataset = null;

    /** @return array<int, string> the step methods run() calls, in order */
    protected function steps(): array
    {
        return ['examinerPool'];
    }

    public function run(): void
    {
        foreach ($this->steps() as $step) {
            $this->{$step}();
        }
    }

    /** Part 1: the examiner pool and its publications. */
    protected function examinerPool(): void
    {
        $reader = app(TemplateLayoutReader::class);
        $importer = app(PoolImporter::class);

        $rows = [];

        foreach ($this->dataset()['examiners'] ?? [] as $i => $examiner) {
            $record = [];

            foreach (self::EXAMINER_FIELDS as $from => $to) {
                $value = $examiner[$from] ?? null;
                $record[$to] = $value === '' ? null : $value;
            }

            $rows[] = $reader->record($record, 'hani_demo_dataset.json, examiners['.$i.']');
        }

        $publications = [];

        foreach ($this->dataset()['publications'] ?? [] as $i => $publication) {
            $publications[mb_strtolower($publication['examiner_email'])][] = [
                'title' => $publication['title'],
                'year' => ($publication['year'] ?? '') === '' ? null : (int) $publication['year'],
                'coauthors' => $publication['coauthors'] ?? null,
                'source' => 'hani_demo_dataset.json, publications['.$i.']',
            ];
        }

        $messages = [];
        $summary = $importer->apply($importer->prepare($rows, $publications, $messages));

        foreach ($messages as $message) {
            $this->command?->warn("  {$message}");
        }

        $this->command?->info(sprintf(
            '  Examiner pool: %d added, %d updated, %d unchanged, %d rejected.',
            $summary[PoolImporter::NEW] + $summary[PoolImporter::DUPLICATE],
            $summary[PoolImporter::UPDATE],
            $summary[PoolImporter::UNCHANGED],
            $summary[PoolImporter::REJECTED],
        ));
    }

    /** The dataset, read once. */
    protected function dataset(): array
    {
        if ($this->dataset !== null) {
            return $this->dataset;
        }

        if (! is_readable(self::DATASET)) {
            throw new RuntimeException('Demo dataset not found at '.self::DATASET);
        }

        return $this->dataset = json_decode(file_get_contents(self::DATASET), true, flags: JSON_THROW_ON_ERROR);
    }
}
