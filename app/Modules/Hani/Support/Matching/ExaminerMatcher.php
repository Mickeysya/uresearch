<?php

namespace App\Modules\Hani\Support\Matching;

use Illuminate\Support\Collection;

/**
 * Ranks examiners by how well their expertise fits a thesis.
 *
 * The contract is the seam: TfIdfMatcher is the default, in plain PHP with no
 * external service, and an embeddings-based matcher could replace it by
 * implementing this and being bound in Hani\ModuleProvider -- see README.md,
 * "Examiner suggestions". Whatever the method, every result must say WHY
 * (matchedTerms), not just how much.
 */
interface ExaminerMatcher
{
    /**
     * @param  array{text: string, keywords: array<int, string>}  $query  the thesis
     * @param  Collection<int, \App\Modules\Hani\Models\Examiner>  $candidates  already filtered to who may be chosen
     * @return array<int, array{examiner_id: int, score: int, matched: array<int, string>}> best first; score 0-100
     */
    public function rank(array $query, Collection $candidates): array;
}
