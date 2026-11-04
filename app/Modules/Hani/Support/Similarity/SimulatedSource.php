<?php

namespace App\Modules\Hani\Support\Similarity;

/**
 * Made-up figures for a demonstration, derived from the file's hash so the
 * same file always gives the same reading. Every reading is labelled
 * "simulated" (source = simulated) wherever it is shown. Never the default.
 */
class SimulatedSource implements SimilaritySource
{
    public function name(): string
    {
        return 'simulated';
    }

    public function read(string $reportBytes): SimilarityReading
    {
        $seed = crc32($reportBytes);
        $index = 5 + $seed % 18;
        $internet = max(1, (int) round($index * 0.6));
        $publications = max(0, (int) round($index * 0.3));

        return new SimilarityReading(
            ok: true,
            source: $this->name(),
            title: null,
            index: (float) $index,
            internet: (float) $internet,
            publications: (float) $publications,
            studentPapers: (float) max(0, $index - $internet - $publications),
            sources: [
                ['rank' => 1, 'source' => 'www.example.com (simulated)', 'type' => 'Internet Source', 'percent' => '3%', 'value' => 3.0],
                ['rank' => 2, 'source' => 'Submitted to Example University (simulated)', 'type' => 'Student Paper', 'percent' => '2%', 'value' => 2.0],
            ],
            exclusions: ['quotes' => 'On', 'bibliography' => 'On', 'matches' => 'Off'],
            engine: 'simulated',
        );
    }
}
