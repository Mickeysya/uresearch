<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Http\Controllers\Concerns\OwnsPublication;
use App\Modules\Norhanis\Models\PublicationMemo;
use App\Modules\Norhanis\Notifications\MemoSigned;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\SignatureImage;
use Illuminate\Http\Request;

/**
 * The online Memo Request Split Budget, which replaced the downloaded
 * template.
 *
 * Two audiences, as in the other sign-off controllers. show(), sign() and
 * decline() are the signer's side: the supervisor, then the centre head, each
 * from a signed link that names the memo and which of the two they are.
 * remind() and revise() are the student's side.
 */
class PublicationMemoController extends Controller
{
    use OwnsPublication;

    // ---- Signer (signed link, no account) ---------------------------------

    public function show(PublicationMemo $memo, string $signer, PublicationSignOffs $signOffs)
    {
        $detail = $memo->publicationDetail()->with('application.student')->firstOrFail();

        return view('norhanis::publication.memo', [
            'memo' => $memo,
            'signer' => $signer,
            'detail' => $detail,
            'student' => $detail->application->student,
            'open' => $this->isTurnOf($memo, $signer),
            'urls' => [
                'sign' => $signOffs->signedUrl('publication.memo.sign', ['memo' => $memo, 'signer' => $signer]),
                'decline' => $signOffs->signedUrl('publication.memo.decline', ['memo' => $memo, 'signer' => $signer]),
            ],
        ]);
    }

    public function sign(Request $request, PublicationMemo $memo, string $signer, PublicationSignOffs $signOffs)
    {
        $here = $signOffs->memoUrl($memo, $signer);

        // A link can be opened again; it can only be signed once, and only
        // in turn: the centre head's link does nothing before the supervisor.
        if (! $this->isTurnOf($memo, $signer)) {
            return redirect($here);
        }

        $signature = SignatureImage::capture($request, 'signature');

        $rules = [
            'agrees' => ['accepted'],
            'signature' => ['required'],
        ];

        // The grant is the supervisor's, so they may put its details right as
        // they sign. The centre head signs what the supervisor requested.
        if ($signer === PublicationMemo::SUPERVISOR) {
            $rules += [
                'grant_name' => ['required', 'string', 'max:150'],
                'grant_cost_centre' => ['required', 'string', 'max:100'],
            ];
        }

        $data = $request->validate($rules, [
            'agrees.accepted' => 'Tick the box to make this request.',
            'signature.required' => 'Sign in the box, or upload an image of your signature.',
        ]);

        $signOffs->signMemo($memo, $signer, $signature, $request->ip(), array_intersect_key($data, [
            'grant_name' => true, 'grant_cost_centre' => true,
        ]));

        $detail = $memo->publicationDetail()->with('application.student')->firstOrFail();
        $application = $detail->application;

        // The next signer in line, if there is one.
        $signOffs->inviteMemoSigner($memo, $detail, $application->student?->name ?? 'A student');

        $submitted = $signOffs->finishIfComplete($application) !== null;

        $application->student?->notify(new MemoSigned($application, $memo, $signer, $submitted));

        return redirect($here);
    }

    public function decline(Request $request, PublicationMemo $memo, string $signer, PublicationSignOffs $signOffs)
    {
        if ($this->isTurnOf($memo, $signer)) {
            $data = $request->validate([
                'decline_reason' => ['required', 'string', 'max:500'],
            ], [
                'decline_reason.required' => 'Say what is wrong, so the student can correct it.',
            ]);

            $signOffs->declineMemo($memo, $signer, $data['decline_reason']);

            $application = $memo->publicationDetail->application;
            $application->student?->notify(new MemoSigned($application, $memo, $signer));
        }

        return redirect($signOffs->memoUrl($memo, $signer));
    }

    /** It is this signer's turn, and the memo has not been sent back. */
    protected function isTurnOf(PublicationMemo $memo, string $signer): bool
    {
        return $memo->awaiting() === $signer;
    }

    // ---- Student ----------------------------------------------------------

    public function remind(Request $request, Application $application, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownDetail($request, $application);

        abort_unless($detail->memo, 404);

        $signer = $detail->memo->awaiting();

        if ($signer === null) {
            return back()->with('error', 'Nobody is being waited on for the memo.');
        }

        $signOffs->inviteMemoSigner($detail->memo, $detail, $request->user()->name);

        return back()->with('status', 'Reminder sent to '.$detail->memo->{"{$signer}_name"}.'.');
    }

    public function revise(Request $request, Application $application, PublicationSignOffs $signOffs)
    {
        $detail = $this->ownDetail($request, $application);

        abort_unless($detail->memo, 404);
        abort_unless($application->status === Application::STATUS_DRAFT, 403);

        $data = $request->validate(self::memoRules(''));

        $signOffs->reviseMemo($detail->memo, $data);
        $signOffs->inviteMemoSigner($detail->memo, $detail, $request->user()->name);

        return back()->with('status', 'Memo updated and sent to '.$detail->memo->supervisor_name.' for signature again.');
    }

    /**
     * The grant and centre details the student supplies. Shared with
     * PublicationController::store(), which nests them under "memo.".
     *
     * @return array<string, array<int, string>>
     */
    public static function memoRules(string $prefix): array
    {
        return [
            $prefix.'grant_name' => ['required', 'string', 'max:150'],
            $prefix.'grant_cost_centre' => ['required', 'string', 'max:100'],
            $prefix.'centre_name' => ['required', 'string', 'max:150'],
            $prefix.'head_name' => ['required', 'string', 'max:150'],
            $prefix.'head_email' => ['required', 'email', 'max:255'],
        ];
    }
}
