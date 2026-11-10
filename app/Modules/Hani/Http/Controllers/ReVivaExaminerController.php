<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\ReVivaExaminerAccounts;
use App\Modules\Hani\Support\ReVivaProcess;
use App\Modules\Hani\Support\WorkingDays;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

/**
 * My Re-evaluations: internal and external examiners. An examiner sees only
 * the rounds where this login's email is a current main examiner.
 */
class ReVivaExaminerController extends Controller
{
    public function index(Request $request)
    {
        $cases = ReVivaDetail::with(['application.student', 'staffDocuments', 'evaluations'])
            ->whereNotNull('sent_to_panel_at')->whereNull('outcome_level')
            ->whereHas('staffDocuments', fn ($q) => $q->where('kind', ReVivaStaffDocument::LETTER)->whereIn('examiner_id', ReVivaExaminerAccounts::examinerIds($request->user())))
            ->get()
            ->map(fn (ReVivaDetail $d) => ($seat = $this->seatOf($d, $request->user())) ? [
                'detail' => $d, 'seat' => $seat, 'examiner' => $d->currentMains()[$seat],
                'daysLeft' => $d->panel_deadline ? WorkingDays::between(today(), $d->panel_deadline) : null,
                'evaluation' => $d->currentEvaluations()->get($seat),
                'studentDocuments' => $d->studentDocuments(),
            ] : null)
            ->filter()->values();

        abort_if($cases->isEmpty() && ! ReVivaProcess::examinesOpenRound($request->user()), 403);

        return view('hani::re_viva.examiner', ['cases' => $cases]);
    }

    public function download(Request $request, ReVivaDetail $detail, ReVivaStaffDocument $document)
    {
        $examiner = $this->examinerOr403($detail, $request->user());
        abort_unless($document->re_viva_detail_id === $detail->id && $document->examiner_id === $examiner->id
            && in_array($document->kind, [ReVivaStaffDocument::LETTER, ReVivaStaffDocument::REPORT_FORM], true), 403);

        return $this->serve($request, $document->path, $document->original_name, $document);
    }

    public function studentDocument(Request $request, ReVivaDetail $detail, ApplicationDocument $document)
    {
        $this->examinerOr403($detail, $request->user());
        abort_unless($detail->studentDocuments()->contains('id', $document->id), 403);

        return $this->serve($request, $document->path, $document->original_name, $document);
    }

    public function upload(Request $request, ReVivaDetail $detail, ReVivaProcess $process)
    {
        $seat = $this->seatOf($detail, $request->user());
        abort_unless($seat, 403);
        abort_unless($detail->application->status === Application::STATUS_PENDING, 403);
        $existing = $detail->currentEvaluations()->get($seat);

        $process->recordEvaluation($detail, $seat, ReVivaController::validateReturn($request, $existing !== null), $request->file('report'), $request->file('signed_letter'), $request->user(), true);

        return back()->with('status', 'Thank you. Your re-evaluation has been sent to CGS.');
    }

    /** The first-time password page for an examiner's new account. Signed. */
    public function setup(Request $request, User $user)
    {
        abort_unless(hash_equals(ReVivaExaminerAccounts::fingerprint($user), (string) $request->query('v')), 403, 'This link has already been used.');

        if ($request->isMethod('GET')) {
            return response()->view('hani::re_viva.account_setup', ['action' => $request->fullUrl(), 'email' => $user->email])->header('Cache-Control', 'private, no-store');
        }

        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $user->forceFill(['password' => Hash::make($data['password']), 'email_verified_at' => now()])->save();

        return redirect()->route('login')->with('status', 'Your password is set. Please sign in.');
    }

    protected function serve(Request $request, string $path, string $name, $subject)
    {
        abort_unless(Storage::disk('local')->exists($path), 404);
        activity('document')->causedBy($request->user())->performedOn($subject)->withProperties(['action' => 'downloaded', 'via' => 're_viva_examiner'])->log('Re-viva document downloaded by an examiner');

        return Storage::disk('local')->download($path, $name);
    }

    protected function examinerOr403(ReVivaDetail $detail, User $user): Examiner
    {
        $seat = $this->seatOf($detail, $user);
        abort_unless($seat, 403);

        return $detail->currentMains()[$seat];
    }

    protected function seatOf(ReVivaDetail $detail, User $user): ?string
    {
        foreach ($detail->currentMains() as $seat => $examiner) {
            if (in_array($examiner->id, ReVivaExaminerAccounts::examinerIds($user), true)) {
                return $seat;
            }
        }

        return null;
    }
}
