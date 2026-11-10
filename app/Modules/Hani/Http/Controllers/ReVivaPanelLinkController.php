<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaPanelCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The examiner's link from their correction letter: the student's three
 * documents, behind a sign-in.
 *
 * Examiners have no UResearch account, so the sign-in is the email the letter
 * went to and a one-time code sent to that address. The link alone shows
 * nothing about the candidate.
 *
 * ValidPanelLink refuses a tampered URL (403) or an expired one (410) before
 * this runs. This then re-checks that the examiner was sent a letter for this
 * round and still holds a main seat (a declined examiner's link stops
 * working), that this browser has signed in for this examiner and round, and
 * that only those three documents of the round are served. Every sign-in and
 * download is logged.
 */
class ReVivaPanelLinkController extends Controller
{
    protected const CODE_ATTEMPTS = 5;

    public function documents(Request $request, ReVivaDetail $detail, Examiner $examiner)
    {
        $examiner = $this->authorise($detail, $examiner);

        if (! $this->signedIn($request, $detail, $examiner)) {
            return $this->private(response()->view('hani::re_viva.panel_signin', [
                'action' => $request->fullUrl(),
                'awaitingCode' => $request->session()->get($this->key('pending', $detail, $examiner)) === true,
            ]));
        }

        $expires = Carbon::createFromTimestamp((int) $request->query('expires'));
        $documents = $this->documentsOf($detail)->map(fn (ApplicationDocument $doc) => [
            'label' => $doc->doc_type,
            'name' => $doc->original_name,
            'url' => URL::temporarySignedRoute('reviva.panel.download', $expires, [
                'detail' => $detail->id, 'examiner' => $examiner->id, 'document' => $doc->id,
            ]),
        ]);

        return $this->private(response()->view('hani::re_viva.panel_link', [
            'detail' => $detail,
            'student' => $detail->application->student,
            'examiner' => $examiner,
            'documents' => $documents,
            'expires' => $expires,
            'signOut' => $request->fullUrl(),
        ]));
    }

    /** Every form posts back to the signed URL: a signature only holds for its own path. */
    public function act(Request $request, ReVivaDetail $detail, Examiner $examiner): RedirectResponse
    {
        return match ($request->validate(['step' => ['required', 'in:email,code,restart,sign_out']])['step']) {
            'email' => $this->sendCode($request, $detail, $examiner),
            'code' => $this->verifyCode($request, $detail, $examiner),
            'restart' => $this->restart($request, $detail, $examiner),
            'sign_out' => $this->signOut($request, $detail, $examiner),
        };
    }

    /**
     * Step 1: the email. The answer is the same whether or not it matched,
     * so the page cannot be used to find out an examiner's address.
     */
    protected function sendCode(Request $request, ReVivaDetail $detail, Examiner $examiner): RedirectResponse
    {
        $examiner = $this->authorise($detail, $examiner);
        $email = $request->validate(['email' => ['required', 'email', 'max:255']])['email'];
        $onRecord = $examiner->usableEmail();

        if ($onRecord && hash_equals(mb_strtolower(trim($onRecord)), mb_strtolower(trim($email)))
            && Cache::add($this->cacheKey('cooldown', $detail, $examiner), true, 60)) {
            $code = (string) random_int(100000, 999999);
            $minutes = (int) config('hani.reviva.panel_code_minutes', 10);

            Cache::put($this->cacheKey('code', $detail, $examiner), ['hash' => $this->hash($code), 'attempts' => 0], now()->addMinutes($minutes));

            try {
                Notification::route('mail', [$onRecord => $examiner->name])->notifyNow(new ReVivaPanelCode($code, $minutes));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $request->session()->put($this->key('pending', $detail, $examiner), true);

        return redirect()->to($request->fullUrl())
            ->with('status', 'If that is the email your letter was sent to, a 6-digit code is on its way. It may take a minute to arrive.');
    }

    /** Step 2: the code. Wrong too many times and a new one must be requested. */
    protected function verifyCode(Request $request, ReVivaDetail $detail, Examiner $examiner): RedirectResponse
    {
        $examiner = $this->authorise($detail, $examiner);
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $key = $this->cacheKey('code', $detail, $examiner);
        $entry = Cache::get($key);

        if (! $entry || $entry['attempts'] >= self::CODE_ATTEMPTS) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => 'That code has expired. Please request a new one.']);
        }

        if (! hash_equals($entry['hash'], $this->hash($code))) {
            Cache::put($key, ['hash' => $entry['hash'], 'attempts' => $entry['attempts'] + 1], now()->addMinutes((int) config('hani.reviva.panel_code_minutes', 10)));
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        Cache::forget($key);
        $request->session()->regenerate();
        $request->session()->forget($this->key('pending', $detail, $examiner));
        $request->session()->put($this->key('verified', $detail, $examiner), now()->addMinutes((int) config('hani.reviva.panel_session_minutes', 60))->timestamp);

        activity('re_viva')->performedOn($detail->application)
            ->withProperties(['action' => 'examiner_signed_in', 'examiner_id' => $examiner->id, 'ip' => $request->ip()])
            ->log('Examiner signed in to a re-viva link');

        return redirect()->to($request->fullUrl());
    }

    /** Back to the email step, e.g. a mistyped address or a code that never came. */
    protected function restart(Request $request, ReVivaDetail $detail, Examiner $examiner): RedirectResponse
    {
        $examiner = $this->authorise($detail, $examiner);
        $request->session()->forget($this->key('pending', $detail, $examiner));

        return redirect()->to($request->fullUrl());
    }

    protected function signOut(Request $request, ReVivaDetail $detail, Examiner $examiner): RedirectResponse
    {
        $examiner = $this->authorise($detail, $examiner);
        $request->session()->forget($this->key('verified', $detail, $examiner));
        $request->session()->regenerate();

        return redirect()->to($request->fullUrl())->with('status', 'You have signed out.');
    }

    public function download(Request $request, ReVivaDetail $detail, Examiner $examiner, ApplicationDocument $document)
    {
        $examiner = $this->authorise($detail, $examiner);

        if (! $this->signedIn($request, $detail, $examiner)) {
            return redirect()->to(URL::temporarySignedRoute('reviva.panel.documents', Carbon::createFromTimestamp((int) $request->query('expires')), [
                'detail' => $detail->id, 'examiner' => $examiner->id,
            ]));
        }

        abort_unless($this->documentsOf($detail)->contains('id', $document->id), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        activity('document')->performedOn($document)
            ->withProperties(['action' => 'viewed', 'via' => 're_viva_examiner_link', 'examiner_id' => $examiner->id, 'ip' => $request->ip()])
            ->log('Re-viva document opened from an examiner link');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type ?: 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return Examiner the record that holds the seat (a merged record resolves to the one kept) */
    protected function authorise(ReVivaDetail $detail, Examiner $examiner): Examiner
    {
        // A letter already emailed carries the id of the record it was sent to;
        // if that record has since been merged, the person is the record kept.
        $examiner = ExaminerMerge::resolve($examiner);

        $lettered = ReVivaStaffDocument::where('re_viva_detail_id', $detail->id)
            ->where('kind', ReVivaStaffDocument::LETTER)
            ->where('examiner_id', $examiner->id)
            ->exists();

        $current = collect($detail->currentMains())->contains(fn (Examiner $e) => $e->id === $examiner->id);

        abort_unless($lettered && $current, 403, 'This link is no longer valid. Please contact the Centre for Graduate Studies.');

        return $examiner;
    }

    protected function signedIn(Request $request, ReVivaDetail $detail, Examiner $examiner): bool
    {
        return (int) $request->session()->get($this->key('verified', $detail, $examiner), 0) > now()->timestamp;
    }

    protected function key(string $what, ReVivaDetail $detail, Examiner $examiner): string
    {
        return "reviva_panel.{$what}.{$detail->id}.{$examiner->id}";
    }

    protected function cacheKey(string $what, ReVivaDetail $detail, Examiner $examiner): string
    {
        return "hani:{$this->key($what, $detail, $examiner)}";
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** Neither page may be kept by a browser or a proxy. */
    protected function private($response)
    {
        return $response->header('Cache-Control', 'private, no-store');
    }

    protected function documentsOf(ReVivaDetail $detail)
    {
        return $detail->studentDocuments()->values();
    }
}
