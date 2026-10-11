<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Norhanis\Http\Controllers\Concerns\OwnsPublication;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Notifications\PaperEvaluationReceived;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\SignatureImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The online Paper Evaluation Sheet (UTP/CGS/040), which replaced the
 * downloaded one.
 *
 * Two audiences, as in PublicationCertificationController. show(), paper(),
 * submit() and decline() are the reviewer's side: reached from a signed link,
 * with no login, and scoped to the one evaluation row the link names.
 * remind(), replace() and respond() are the student's side.
 */
class PublicationEvaluationController extends Controller
{
    use OwnsPublication;

    // ---- Reviewer (signed link, no account) -------------------------------

    public function show(PublicationEvaluation $evaluation, PublicationSignOffs $signOffs)
    {
        $detail = $evaluation->publicationDetail()->with('authors')->firstOrFail();

        return view('norhanis::publication.evaluate', [
            'evaluation' => $evaluation,
            'detail' => $detail,
            'urls' => [
                'paper' => $signOffs->signedUrl('publication.evaluate.paper', ['evaluation' => $evaluation]),
                'submit' => $signOffs->signedUrl('publication.evaluate.submit', ['evaluation' => $evaluation]),
                'decline' => $signOffs->signedUrl('publication.evaluate.decline', ['evaluation' => $evaluation]),
            ],
        ]);
    }

    /** The manuscript the student uploaded for review, and nothing else. */
    public function paper(PublicationEvaluation $evaluation)
    {
        abort_if($evaluation->isReplaced(), 403);

        $paper = $evaluation->publicationDetail->application->documents()
            ->where('doc_type', PublicationSignOffs::MANUSCRIPT)
            ->latest('id')
            ->first();

        abort_unless($paper?->exists(), 404);

        return Storage::disk('local')->download($paper->path, $paper->original_name);
    }

    public function submit(Request $request, PublicationEvaluation $evaluation, PublicationSignOffs $signOffs)
    {
        $here = $signOffs->evaluationUrl($evaluation);

        // A link can be opened again; it can only be answered once.
        if (! $this->isOpen($evaluation)) {
            return redirect($here);
        }

        $signature = SignatureImage::capture($request, 'signature');

        $rules = [
            'additional_comments' => [
                'nullable', 'string', 'max:5000',
                // The student has to know what to correct, or why it failed.
                Rule::requiredIf(fn () => in_array($request->input('recommendation'), [
                    PublicationEvaluation::CORRECTIONS, PublicationEvaluation::REJECTED,
                ], true)),
            ],
            'recommendation' => ['required', Rule::in(array_keys(PublicationEvaluation::recommendations()))],
            'confirms' => ['accepted'],
            'signature' => ['required'],
        ];

        foreach (array_keys(PublicationEvaluation::CRITERIA) as $criterion) {
            $rules["{$criterion}_rating"] = ['required', 'integer', 'between:1,5'];
            $rules["{$criterion}_comment"] = ['nullable', 'string', 'max:2000'];
        }

        $sheet = $request->validate($rules, [
            '*_rating.required' => 'Give a rating from 1 to 5.',
            'additional_comments.required' => 'Say what needs correcting, or why the paper is rejected, so the student can act on it.',
            'recommendation.required' => 'Choose a recommendation.',
            'confirms.accepted' => 'Tick the box to confirm this is your own evaluation.',
            'signature.required' => 'Sign in the box, or upload an image of your signature.',
        ]);

        unset($sheet['confirms'], $sheet['signature']);

        $document = $signOffs->recordEvaluation($evaluation, $sheet, $signature, $request->ip());

        $application = $evaluation->publicationDetail->application;
        $submitted = $signOffs->finishIfComplete($application) !== null;

        $application->student?->notify(new PaperEvaluationReceived($application, $evaluation, $document, $submitted));

        return redirect($here);
    }

    public function decline(Request $request, PublicationEvaluation $evaluation, PublicationSignOffs $signOffs)
    {
        if ($this->isOpen($evaluation)) {
            $data = $request->validate([
                'decline_reason' => ['required', 'string', 'max:500'],
            ], [
                'decline_reason.required' => 'Give a short reason, so the student knows to ask someone else.',
            ]);

            $signOffs->decline($evaluation, $data['decline_reason']);

            $application = $evaluation->publicationDetail->application;
            $application->student?->notify(new PaperEvaluationReceived($application, $evaluation));
        }

        return redirect($signOffs->evaluationUrl($evaluation));
    }

    /** Still waiting for this reviewer's answer. */
    protected function isOpen(PublicationEvaluation $evaluation): bool
    {
        return ! $evaluation->isSubmitted() && ! $evaluation->isDeclined() && ! $evaluation->isReplaced();
    }

    // ---- Student ----------------------------------------------------------

    public function remind(Request $request, Application $application, PublicationEvaluation $evaluation, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownEvaluation($request, $application, $evaluation);

        if (! $this->isOpen($evaluation)) {
            return back()->with('error', "{$evaluation->reviewer_name} is no longer being waited on.");
        }

        $signOffs->inviteReviewer($evaluation, $detail, $request->user()->name);

        return back()->with('status', "Reminder sent to {$evaluation->reviewer_name}.");
    }

    public function replace(Request $request, Application $application, PublicationEvaluation $evaluation, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownEvaluation($request, $application, $evaluation);

        abort_unless($application->status === Application::STATUS_DRAFT && $evaluation->canBeReplaced(), 403);

        $data = $request->validate([
            'reviewer_name' => ['required', 'string', 'max:150'],
            'reviewer_email' => ['required', 'email', 'max:255', 'ends_with:'.PublicationEvaluation::REVIEWER_EMAIL_DOMAIN],
        ], [
            'reviewer_email.ends_with' => 'An internal reviewer must have a UTP email address ('.PublicationEvaluation::REVIEWER_EMAIL_DOMAIN.').',
        ]);

        $others = $detail->activeEvaluations->where('id', '!=', $evaluation->id)->pluck('reviewer_email')->all();
        PublicationSignOffs::assertEligibleReviewers($detail->authors->pluck('email')->all(), array_merge($others, [$data['reviewer_email']]), 'reviewer_email');

        $replacement = $signOffs->replaceReviewer($evaluation, $data['reviewer_name'], $data['reviewer_email']);
        $signOffs->inviteReviewer($replacement, $detail, $request->user()->name);

        return back()->with('status', "{$replacement->reviewer_name} has been emailed a link to evaluate your paper.");
    }

    public function respond(
        Request $request,
        Application $application,
        PublicationEvaluation $evaluation,
        PublicationSignOffs $signOffs,
        DocumentStore $documents,
    ) {
        $this->ownEvaluation($request, $application, $evaluation);

        abort_unless($application->status === Application::STATUS_DRAFT && $evaluation->needsResponse(), 403);

        $data = $request->validate([
            'response' => ['required', 'string', 'max:5000'],
            'corrected_paper' => DocumentStore::rules(true),
        ], [
            'response.required' => 'Reply to the reviewer\'s comments.',
            'corrected_paper.required' => 'Upload the corrected paper.',
        ]);

        $documents->attach($application, $request->file('corrected_paper'), 'Corrected Conference/Journal Paper');
        $signOffs->respond($evaluation, $data['response']);

        if ($signOffs->finishIfComplete($application)) {
            return redirect()
                ->route('publication.sign-offs', $application)
                ->with('status', 'Response saved. Every sign-off is now in, and your application has been sent to your supervisor.');
        }

        return back()->with('status', 'Response saved.');
    }

    /** The application's own evaluation row, for the student who filed it. */
    protected function ownEvaluation(Request $request, Application $application, PublicationEvaluation $evaluation): PublicationDetail
    {
        $detail = $this->ownDetail($request, $application);

        // The evaluation must belong to this application, not merely exist.
        abort_unless($evaluation->publication_detail_id === $detail->id, 404);

        return $detail;
    }
}
