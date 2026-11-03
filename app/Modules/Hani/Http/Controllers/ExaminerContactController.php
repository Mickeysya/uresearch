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

    protected function canEditEmail(Request $request): bool
    {
        return in_array($request->user()->role, [Role::SENIOR_EXEC_CGS, Role::NON_EXEC_CGS], true);
    }

    protected function authoriseView(Request $request, Examiner $examiner): void
    {
        abort_unless(in_array($request->user()->role, [Role::SENIOR_EXEC_CGS, Role::NON_EXEC_CGS], true), 403);
    }
}
