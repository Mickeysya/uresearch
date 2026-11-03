<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Http\Controllers\Concerns\OwnsPublication;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationSigned;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\SignatureImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The online Authorship Certification, which replaced the downloaded form.
 *
 * Two audiences. show() and sign() are the co-author's side: reached from a
 * signed link in an email, with no login, and scoped to the one author row
 * the link names. signOffs() and remind() are the student's side: who has
 * signed, and a nudge for whoever has not.
 */
class PublicationCertificationController extends Controller
{
    use OwnsPublication;

    // ---- Co-author (signed link, no account) ------------------------------

    public function show(PublicationAuthor $author)
    {
        $detail = $author->publicationDetail()->with('authors')->firstOrFail();

        return view('norhanis::publication.certify', [
            'author' => $author,
            'detail' => $detail,
            'action' => URL::signedRoute('publication.certify.sign', ['author' => $author], absolute: false),
        ]);
    }

    public function sign(Request $request, PublicationAuthor $author, PublicationSignOffs $signOffs)
    {
        $here = URL::signedRoute('publication.certify', ['author' => $author], absolute: false);

        // A link can be opened twice; it can only be signed once.
        if ($author->hasSigned()) {
            return redirect($here);
        }

        $signature = SignatureImage::capture($request, 'signature');

        $data = $request->validate([
            'certifies' => ['accepted'],
            'signature' => ['required'],
            'has_conflict' => ['required', 'in:no,yes'],
            'conflict_disclosure' => ['nullable', 'required_if:has_conflict,yes', 'string', 'max:2000'],
        ], [
            'certifies.accepted' => 'Tick the box to certify the authorship.',
            'signature.required' => 'Sign in the box, or upload an image of your signature.',
            'conflict_disclosure.required_if' => 'Describe the conflict of interest you are disclosing.',
        ]);

        $signOffs->sign(
            $author,
            $signature,
            $data['has_conflict'] === 'yes' ? $data['conflict_disclosure'] : null,
            $request->ip(),
        );

        $detail = $author->publicationDetail()->with('authors', 'activeEvaluations', 'application.student')->firstOrFail();
        $form = $signOffs->finishIfComplete($detail->application);

        $detail->application->student?->notify(new AuthorshipCertificationSigned(
            $detail->application,
            $author->author_name,
            $detail->authors->filter->hasSigned()->count(),
            $detail->authors->count(),
            $form,
        ));

        return redirect($here);
    }

    // ---- Student ----------------------------------------------------------

    public function signOffs(Request $request, Application $application, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownDetail($request, $application);

        // Normally a no-op: the last signature already submitted it. This
        // picks up an application whose form could not be saved at that moment.
        if ($signOffs->finishIfComplete($application)) {
            $application->refresh();
        }

        return view('norhanis::publication.sign_offs', [
            'application' => $application,
            'detail' => $detail,
            // Keyed by label, so the view can link each generated PDF.
            'generated' => $application->documents()
                ->where(fn ($query) => $query
                    ->where('doc_type', 'Authorship Certification Form')
                    ->orWhere('doc_type', 'like', 'Paper Evaluation Sheet%'))
                ->oldest('id')
                ->get()
                ->keyBy('doc_type'),
            'replaced' => $detail->evaluations()->whereNotNull('replaced_at')->get(),
        ]);
    }

    public function remind(Request $request, Application $application, PublicationAuthor $author, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownDetail($request, $application);

        // The author must belong to this application, not merely exist.
        abort_unless($author->publication_detail_id === $detail->id, 404);

        if ($author->hasSigned() || ! $author->email) {
            return back()->with('error', "{$author->author_name} has already signed.");
        }

        $signOffs->invite($author, $detail, $request->user()->name);

        return back()->with('status', "Reminder sent to {$author->author_name}.");
    }
}
