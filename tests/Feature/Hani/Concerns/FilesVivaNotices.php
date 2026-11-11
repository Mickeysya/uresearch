<?php

namespace Tests\Feature\Hani\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * A valid Notice of Thesis Submission POST body, for the tests that need a
 * notice on file before they get to what they are testing.
 */
trait FilesVivaNotices
{
    /** A fictional abstract of the given length, in words. */
    protected function abstractOf(int $words): string
    {
        $vocabulary = ['examiner', 'matching', 'constraint', 'pool', 'expertise', 'thesis', 'panel', 'conflict', 'interest', 'ranking'];

        return implode(' ', array_map(fn ($i) => $vocabulary[$i % count($vocabulary)], range(0, $words - 1)));
    }

    protected function noticeFields(array $overrides = []): array
    {
        return $overrides + [
            'thesis_title' => 'Constraint-based examiner matching',
            'abstract' => $this->abstractOf(150),
            'keywords' => 'examiner matching; constraint satisfaction; expertise ranking',
            'expected_softbound_date' => now()->addMonths(4)->toDateString(),
            'publication_papers' => [UploadedFile::fake()->create('accepted.pdf', 100, 'application/pdf')],
            'research_methodology_result' => UploadedFile::fake()->create('rm-result.pdf', 50, 'application/pdf'),
        ];
    }

    /** The AE's Verification with every eligibility item marked Verified. */
    protected function verifyNotice(\App\Modules\Core\Models\User $ae, \App\Modules\Core\Models\Application $notice, array $overrides = [])
    {
        $items = array_fill_keys(array_keys(\App\Modules\Hani\Support\VivaChecklist::itemsFor($notice->student)), 'verified');

        return $this->actingAs($ae)->post(route('viva.decide', $notice), $overrides + ['verification' => 'verified', 'items' => $items]);
    }
}
