<?php

namespace App\Modules\Hani\Support;

/** The worked example on the student's forms. Every name in it is invented. */
final class ReVivaExampleForm
{
    public static function data(): array
    {
        $internal = [
            ['The abstract needs to reflect the study synopsis of each chapter.', 'The abstract was fully rewritten to reflect objectives, methodology, sample size, analysis technique, key findings and contributions.', 'p. vii'],
            ['The problem statement lacks supporting evidence.', 'Recent evidence was added to support the problem statement.', 'pp. 3–5'],
            ['State the research objectives in measurable terms.', 'All objectives were reworded to be measurable.', 'p. 7'],
            ['The literature review is descriptive.', 'The review was restructured around themes and critically synthesised.', 'Ch. 2'],
            ['Justify the sample size.', 'A power analysis and a justification were added.', 'pp. 61–62'],
            ['Explain the choice of analysis technique.', 'A rationale for the chosen technique was added.', 'p. 66'],
            ['Tables lack clear captions.', 'All captions were revised.', 'Ch. 4'],
            ['Discuss the limitations of the study.', 'A limitations section was added.', 'pp. 131–132'],
            ['References do not follow the required style.', 'All references were reformatted.', 'Entire thesis'],
        ];
        $external = [
            ['The title does not reflect the scope.', 'The title was revised.', 'p. i'],
            ['Clarify the research gap.', 'The gap is now stated explicitly.', 'p. 6'],
            ['Define the key terms.', 'A definition of terms section was added.', 'pp. 9–10'],
            ['The theoretical framework is unclear.', 'The framework was redrawn and explained.', 'pp. 44–46'],
            ['Hypotheses should be linked to the objectives.', 'A mapping table was added.', 'p. 48'],
            ['Describe the instrument validation.', 'Pilot and validity results were added.', 'pp. 68–71'],
            ['Report the assumption tests.', 'Assumption tests were reported.', 'pp. 82–85'],
            ['Figures are low resolution.', 'All figures were replaced.', 'Ch. 4'],
            ['Discussion should compare with prior studies.', 'The discussion was expanded with comparisons.', 'Ch. 5'],
            ['State the contribution to practice.', 'A contributions section was added.', 'p. 135'],
            ['Provide recommendations for future work.', 'Recommendations were added.', 'p. 137'],
            ['Correct language errors throughout.', 'The thesis was professionally proofread.', 'Entire thesis'],
        ];

        return [
            'example' => true, 'name' => 'Aisyah Binti Rahman Example', 'matric' => '99990001', 'phd' => false,
            'degreeName' => 'MPhil in Example Studies', 'programme' => 'Example Studies',
            'thesisTitle' => 'A Fictional Study of Example Practices in Imaginary Organisations',
            'supervisor' => 'Dr Example Supervisor', 'coSupervisor' => 'Dr Sample Co-Supervisor',
            'examiner1' => 'Prof Example Internal', 'examiner2' => 'Prof Sample External', 'chairman' => 'Assoc Prof Example Chairman',
            'rcsDate' => '15 March 2026', 'studyMode' => 'Full Time', 'confidential' => false,
            'address' => '12 Jalan Contoh, Taman Sampel, 00000 Bandar Rekaan', 'date' => '20 April 2026',
            'internalRows' => $internal, 'externalRows' => $external,
        ];
    }
}
