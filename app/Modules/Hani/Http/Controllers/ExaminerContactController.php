<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Support\ExaminerContact;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The examiner page, and keeping an examiner's email current.
 *
 * Non-Executive CGS (Encik Syahmi) sends the appointment letters, so he is
 * the one who finds out an address is out of date. He may change the EMAIL
 * and nothing else: the update below validates and writes that one field,
 * whatever else is posted. The Senior Executive edits everything on the
 * full examiner form (ExaminerAdminController).
 */
class ExaminerContactController extends Controller
{
    public function show(Request $request, Examiner $examiner)
    {
        $this->authoriseView($request, $examiner);

        return view('hani::examiner_admin.show', [
            'examiner' => $examiner->load('expertiseKeywords', 'contactHistory.changedBy'),
            'canEditAll' => $request->user()->role === Role::SENIOR_EXEC_CGS,
            'canEditEmail' => $this->canEditEmail($request),
            'canReport' => app(ExaminerContact::class)->canReport($request->user(), $examiner),
        ]);
    }

    public function editEmail(Request $request, Examiner $examiner)
    {
        abort_unless($this->canEditEmail($request), 403);

        return view('hani::examiner_admin.email', ['examiner' => $examiner]);
    }

    public function updateEmail(Request $request, Examiner $examiner, ExaminerContact $contact)
    {
        abort_unless($this->canEditEmail($request), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150', Rule::unique('examiners', 'email')->ignore($examiner->id)],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'email.unique' => 'Another examiner in the pool already has this email.',
            'reason.required' => 'Say why the email is changing, e.g. where the new address came from.',
        ]);

        $changed = $contact->changeEmail($examiner, $data['email'], $request->user(), 'email_form', $data['reason']);

        return redirect()
            ->route('examiner-admin.show', $examiner)
            ->with('status', $changed ? "Email for {$examiner->name} updated." : 'That is already the email on record. Nothing was changed.');
    }

    /**
     * "Email not working", from the examiner page or the viva examiner
     * progress screen. A reason is required; it goes to CGS and into the
     * contact history.
     */
    public function reportEmail(Request $request, Examiner $examiner, ExaminerContact $contact)
    {
        abort_unless($contact->canReport($request->user(), $examiner), 403);

        if ($examiner->needsEmail()) {
            return back()->with('error', "No email is on record for {$examiner->name}, so there is nothing to report.");
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say what happened, e.g. the invitation bounced.',
        ]);

        $contact->report($examiner, $request->user(), $data['reason']);

        return back()->with('status', "{$examiner->name}'s email has been marked as not working. CGS has been asked to update it; nothing is sent to this examiner until then.");
    }

    protected function canEditEmail(Request $request): bool
    {
        return in_array($request->user()->role, [Role::SENIOR_EXEC_CGS, Role::NON_EXEC_CGS], true);
    }

    /** CGS, or an Academic Executive who could report this examiner's email. */
    protected function authoriseView(Request $request, Examiner $examiner): void
    {
        abort_unless(
            in_array($request->user()->role, [Role::SENIOR_EXEC_CGS, Role::NON_EXEC_CGS], true)
                || app(ExaminerContact::class)->canReport($request->user(), $examiner),
            403,
        );
    }
}
