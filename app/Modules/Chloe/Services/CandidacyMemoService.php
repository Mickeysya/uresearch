<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyAppeal;

/**
 * Builds the digitally approved memo when the Dean approves. Phase 2 only fixes the contract the service relies on:
 * generate() stores a PDF and returns [path, filename], or null while no generator is installed. Phase 3 fills it in.
 */
class CandidacyMemoService
{
    /** @return array{0:string,1:string}|null */
    public function generate(CandidacyAppeal $appeal): ?array
    {
        return null;
    }
}
