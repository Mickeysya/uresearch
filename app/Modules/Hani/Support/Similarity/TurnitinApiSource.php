<?php

namespace App\Modules\Hani\Support\Similarity;

/**
 * Turnitin's own API, for when UTP licenses it. A STUB: it reads nothing
 * yet and says so, and the form falls back to the student's declared figure.
 *
 * To implement it, with a licence:
 *   1. Put the key in .env as TURNITIN_API_KEY and the tenant URL as
 *      TURNITIN_API_URL (both proposed for .env.example in SHARED_CHANGES.md).
 *   2. Turnitin's Core API (TCA) works on submissions, not uploaded reports:
 *      create a submission for the thesis, upload the file, request a
 *      similarity report, then poll (or take the webhook) for the overall
 *      match percentage and the top sources.
 *   3. Map the result onto a SimilarityReading (index, the three breakdown
 *      figures, the top sources, the exclusion settings used) and set
 *      config('hani.similarity.source') to 'turnitin_api'.
 * read() would then take the thesis rather than a report; the controller
 * already keeps both files.
 */
class TurnitinApiSource implements SimilaritySource
{
    public function name(): string
    {
        return 'turnitin_api';
    }

    public function read(string $reportBytes): SimilarityReading
    {
        $configured = filled(config('hani.similarity.turnitin_api.key')) && filled(config('hani.similarity.turnitin_api.url'));

        return SimilarityReading::failed($this->name(), $configured
            ? 'The Turnitin API connection is configured but not implemented yet.'
            : 'Turnitin API access needs a UTP licence and is not configured.');
    }
}
