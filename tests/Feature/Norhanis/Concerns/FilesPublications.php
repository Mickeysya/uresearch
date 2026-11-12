<?php

namespace Tests\Feature\Norhanis\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use Illuminate\Http\UploadedFile;

/**
 * A valid publication application, and the people who sign it off.
 *
 * Three test files file publications; the payload is long and the same in
 * each, and so are the signed links a co-author or reviewer follows.
 */
trait FilesPublications
{
    /** A 1x1 PNG, as the signature box would post it. */
    protected const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** A complete journal application: one author (the student), two reviewers. */
    protected function payload(array $overrides = []): array
    {
        $payload = $overrides + [
            'type_of_request' => 'publication_journal',
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => '2500.00',
            'currency_type' => 'MYR',
            'cost_centre' => 'cgs_pay_and_claims',
            'conference_start_date' => now()->addDays(30)->toDateString(),
            'conference_end_date' => now()->addDays(32)->toDateString(),
            'authors' => [['author_name' => 'Ahmad Danial', 'email' => 'student@test.my']],
            'applicant_certifies' => '1',
            'applicant_signature' => self::SIGNATURE,
            'reviewers' => $this->reviewers(2),
        ] + $this->documents();

        // Every author field is required; a test that names only a name and
        // email gets the rest filled in. To test a missing field, unset it
        // from the returned payload.
        if (is_array($payload['authors'])) {
            $payload['authors'] = array_map(
                fn ($author) => is_array($author) ? $author + self::AUTHOR_DEFAULTS : $author,
                $payload['authors'],
            );
        }

        return $payload;
    }

    /** What a test's authors get unless it says otherwise. */
    protected const AUTHOR_DEFAULTS = [
        'designation' => 'PhD Student',
        'organisation' => 'Universiti Teknologi PETRONAS',
        'role_contribution' => 'Methodology and writing',
    ];

    /** @return array<int, array{name: string, email: string}> */
    protected function reviewers(int $count): array
    {
        return array_slice([
            ['name' => 'Dr. Siti Mariam', 'email' => 'siti.mariam@utp.edu.my'],
            ['name' => 'Dr. Faridah Osman', 'email' => 'faridah.osman@utp.edu.my'],
            ['name' => 'Dr. Tan Wei Ming', 'email' => 'tan.weiming@utp.edu.my'],
        ], 0, $count);
    }

    /** Every document a publication must carry when it is filed. */
    protected function documents(): array
    {
        $fields = [
            'invoice', 'index_proof', 'turnitin_report', 'letter_of_acceptance',
            'conference_journal_details', 'manuscript',
            'response_to_reviewer_comment', 'corrected_conference_journal_paper',
        ];

        return array_combine($fields, array_map(fn ($field) => $this->pdf($field.'.pdf'), $fields));
    }

    protected function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    protected function withCoAuthor(): array
    {
        return ['authors' => [
            ['author_name' => 'Ahmad Danial', 'email' => 'student@test.my'],
            ['author_name' => 'Prof. Li Wen', 'email' => 'li.wen@example.edu', 'organisation' => 'Partner University'],
        ]];
    }

    /** File an application as $student, then carry on as a guest. */
    protected function file($student, array $overrides = []): Application
    {
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        // Co-authors and reviewers have no account.
        auth()->logout();

        return Application::where('module_type', 'publication')->latest('id')->firstOrFail();
    }

    protected function signOffs(): PublicationSignOffs
    {
        return app(PublicationSignOffs::class);
    }

    /** A signed link for one of the reviewer's routes. */
    protected function evaluationUrl(PublicationEvaluation $evaluation, string $route = 'publication.evaluate'): string
    {
        return $this->signOffs()->signedUrl($route, ['evaluation' => $evaluation]);
    }

    /** A signed link for one of the co-author's routes. */
    protected function certifyUrl(PublicationAuthor $author, string $route = 'publication.certify'): string
    {
        return $this->signOffs()->signedUrl($route, ['author' => $author]);
    }

    /** A filled-in Paper Evaluation Sheet, as the reviewer's form posts it. */
    protected function sheet(array $overrides = []): array
    {
        return $overrides + [
            'objective_rating' => 4,
            'originality_rating' => 4,
            'methodology_rating' => 3,
            'results_rating' => 5,
            'methodology_comment' => 'Sound, though the sampling interval is not justified.',
            'recommendation' => PublicationEvaluation::RECOMMENDED,
            'confirms' => '1',
            'signature' => self::SIGNATURE,
        ];
    }

    /** Have every reviewer still being waited on recommend the paper. */
    protected function completeEvaluations(Application $application): void
    {
        $pending = PublicationEvaluation::whereNull('replaced_at')->whereNull('signed_at')
            ->whereHas('publicationDetail', fn ($query) => $query->where('application_id', $application->id))
            ->get();

        foreach ($pending as $evaluation) {
            $this->post($this->evaluationUrl($evaluation, 'publication.evaluate.submit'), $this->sheet())
                ->assertSessionHasNoErrors();
        }
    }
}
